<?php
// usuarios.php - GESTIÓN DE EQUIPO (CORRECCIÓN DE COLOR EN WIDGETS)
session_start();

$rutas_db = [__DIR__ . '/db.php', __DIR__ . '/includes/db.php', 'db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$id_user_sesion = $_SESSION['usuario_id'];
$stmtCheck = $conexion->prepare("SELECT id_rol FROM usuarios WHERE id = ?");
$stmtCheck->execute([$id_user_sesion]);
$rol_actual = $stmtCheck->fetchColumn();

if($rol_actual > 2) { header("Location: dashboard.php"); exit; }

// --- 1. PROCESAR ALTA ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_usuario'])) {
    // Validamos Token CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Error de seguridad: Solicitud no autorizada.");
    }

    $nombre = trim($_POST['nombre']);
    $user = trim($_POST['usuario']);
    $pass = $_POST['password'];
    $rol = $_POST['id_rol'];

    $check = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = ?");
    $check->execute([$user]);
    
    if ($check->rowCount() > 0) {
        $error = "El usuario ya existe.";
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $sql = "INSERT INTO usuarios (nombre_completo, usuario, password, id_rol, activo) VALUES (?, ?, ?, ?, 1)";
        if ($conexion->prepare($sql)->execute([$nombre, $user, $hash, $rol])) {
            header("Location: usuarios.php?msg=creado"); exit;
        }
    }
}

// --- 2. PROCESAR EDICIÓN ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['editar_usuario'])) {
    // Validamos Token CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Error de seguridad: Solicitud no autorizada.");
    }

    $id_upd = intval($_POST['id_usuario_edit']);
    $nombre = trim($_POST['nombre']);
    $rol = intval($_POST['id_rol']);
    $whatsapp = $_POST['whatsapp'];

    $sql = "UPDATE usuarios SET nombre_completo=?, id_rol=?, whatsapp=? WHERE id=?";
    $params = [$nombre, $rol, $whatsapp, $id_upd];
    
    if(!empty($_POST['password'])) {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $sql = "UPDATE usuarios SET nombre_completo=?, id_rol=?, whatsapp=?, password=? WHERE id=?";
        $params = [$nombre, $rol, $whatsapp, $hash, $id_upd];
    }
    
    $conexion->prepare($sql)->execute($params);
    header("Location: usuarios.php?msg=editado"); exit;
}

// --- 3. CAMBIO DE ESTADO (PROTEGIDO) ---
if (isset($_GET['toggle'])) {
    // Validamos Token CSRF en la URL
    if (!isset($_GET['token']) || $_GET['token'] !== $_SESSION['csrf_token']) {
        die("Error de seguridad: Token inválido.");
    }

    $id_edit = intval($_GET['toggle']);
    $st = intval($_GET['st']) == 1 ? 0 : 1;
    
    if ($id_edit == $id_user_sesion || $id_edit == 1) { 
        $err = ($id_edit == 1) ? "admin" : "self";
        header("Location: usuarios.php?err=$err"); exit; 
    }

    $conexion->prepare("UPDATE usuarios SET activo = ? WHERE id = ?")->execute([$st, $id_edit]);
    header("Location: usuarios.php"); exit;
}

// --- 4. DATOS ---
$total_users = $conexion->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
$total_activos = $conexion->query("SELECT COUNT(*) FROM usuarios WHERE activo = 1")->fetchColumn();
$usuarios = $conexion->query("SELECT u.*, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id ORDER BY u.id_rol ASC")->fetchAll(PDO::FETCH_ASSOC);
$roles_db = $conexion->query("SELECT * FROM roles")->fetchAll(PDO::FETCH_ASSOC);
// OBTENER COLOR SEGURO (ESTÁNDAR PREMIUM)
$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_principal FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_principal'])) $color_sistema = $dataC['color_principal'];
    }
} catch (Exception $e) { }
?>

<?php include 'includes/layout_header.php'; ?></div>



<div class="header-blue" style="background-color: <?php echo $color_sistema; ?> !important;">
    <i class="bi bi-people bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
            <div>
                <h2 class="font-cancha mb-0 text-white">Gestión de Equipo</h2>
                <p class="opacity-75 mb-0 text-white small">Administración de accesos y perfiles del plantel.</p>
            </div>
            <div class="d-flex gap-2">
                <a href="roles.php" class="btn btn-outline-light fw-bold rounded-pill px-3 shadow-sm">
                    <i class="bi bi-shield-lock me-1"></i> ROLES
                </a>
                <button class="btn btn-light text-primary fw-bold rounded-pill px-3 shadow" data-bs-toggle="modal" data-bs-target="#modalAlta">
                    <i class="bi bi-person-plus-fill me-1"></i> NUEVO
                </button>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Total Equipo</div>
                        <div class="widget-value text-white"><?php echo $total_users; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white">
                        <i class="bi bi-person-badge"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Miembros Activos</div>
                        <div class="widget-value text-white"><?php echo $total_activos; ?></div>
                    </div>
                    <div class="icon-box bg-success bg-opacity-20 text-white">
                        <i class="bi bi-check-all"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Niveles de Rango</div>
                        <div class="widget-value text-white"><?php echo count($roles_db); ?></div>
                    </div>
                    <div class="icon-box bg-warning bg-opacity-20 text-white">
                        <i class="bi bi-award"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget border-info">
                    <div>
                        <div class="widget-label">Estado de Red</div>
                        <div class="widget-value text-white" style="font-size: 1.1rem;">CONECTADO</div>
                    </div>
                    <div class="icon-box bg-info bg-opacity-20 text-white">
                        <i class="bi bi-broadcast"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <?php if(isset($_GET['err'])): ?>
        <div class='alert alert-warning border-0 shadow-sm fw-bold mb-3 small'>
            <i class='bi bi-exclamation-triangle me-2'></i>
            <?php echo ($_GET['err'] == 'self') ? "No puedes desactivar tu propia cuenta." : "El SuperAdmin no puede ser desactivado."; ?>
        </div>
    <?php endif; ?>

    <div class="card card-custom">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0" style="font-size: 0.88rem;">
                    <thead>
                        <tr class="bg-light">
                            <th class="ps-4 py-3">Miembro</th>
                            <th>Rango</th>
                            <th>Contacto</th>
                            <th>Estado</th>
                            <th class="text-end pe-4">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($usuarios as $user): 
                            $foto = !empty($user['foto_perfil']) ? 'uploads/'.$user['foto_perfil'] : 'img/no-image.png';
                            $data_json = htmlspecialchars(json_encode($user));
                        ?>
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center">
                                    <img src="<?php echo $foto; ?>" class="avatar-circle me-2">
                                    <div>
                                        <div class="fw-bold text-dark lh-1 mb-1"><?php echo htmlspecialchars($user['nombre_completo']); ?></div>
                                        <div class="text-muted small">@<?php echo htmlspecialchars($user['usuario']); ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?php echo $user['id_rol'] == 1 ? 'bg-danger-subtle text-danger' : 'bg-primary-subtle text-primary'; ?> rounded-pill border" style="font-size: 0.65rem;">
                                    <?php echo strtoupper($user['nombre_rol']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="small fw-bold"><?php echo $user['whatsapp'] ?: '-'; ?></div>
                                <div class="text-muted small"><?php echo $user['email'] ?: 'Sin email'; ?></div>
                            </td>
                            <td>
                                <span class="fw-bold small <?php echo $user['activo'] == 1 ? 'text-success' : 'text-muted'; ?>">
                                    <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i> <?php echo $user['activo'] == 1 ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </td>
                            <td class="text-end pe-4">
                                <button onclick='abrirEditar(<?php echo $data_json; ?>)' class="btn-action text-primary" title="Editar"><i class="bi bi-pencil-fill"></i></button>
                                <a href="?toggle=<?php echo $user['id']; ?>&st=<?php echo $user['activo']; ?>&token=<?php echo $_SESSION['csrf_token']; ?>"
                                   class="btn-action <?php echo $user['activo'] == 1 ? 'text-danger' : 'text-success'; ?> ms-1"
                                   title="Cambiar Estado">
                                    <i class="bi <?php echo $user['activo'] == 1 ? 'bi-person-x-fill' : 'bi-person-check-fill'; ?>"></i>
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalAlta" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header bg-primary text-white py-3" style="border-radius: 20px 20px 0 0;">
                <h6 class="modal-title fw-bold">NUEVO INTEGRANTE</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <div class="mb-3"><label class="small fw-bold">Nombre Completo</label><input type="text" name="nombre" class="form-control" required></div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="small fw-bold">Usuario</label><input type="text" name="usuario" class="form-control" required></div>
                        <div class="col-6"><label class="small fw-bold">Contraseña</label><input type="password" name="password" class="form-control" required></div>
                    </div>
                    <div class="mb-4">
                        <label class="small fw-bold">Rol</label>
                        <select name="id_rol" class="form-select" required>
                            <?php foreach($roles_db as $r): ?><option value="<?php echo $r['id']; ?>"><?php echo $r['nombre']; ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="crear_usuario" class="btn btn-primary w-100 fw-bold py-2 rounded-pill">REGISTRAR</button>
                </form>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditar" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg" style="border-radius: 20px;">
            <div class="modal-header bg-dark text-white py-3" style="border-radius: 20px 20px 0 0;">
                <h6 class="modal-title fw-bold">EDITAR PERFIL: <span id="edit_username_title"></span></h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST">
    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
    <input type="hidden" name="id_usuario_edit" id="edit_id">
                    <div class="mb-3"><label class="small fw-bold">Nombre Completo</label><input type="text" name="nombre" id="edit_nombre" class="form-control" required></div>
                    <div class="row g-2 mb-3">
                        <div class="col-6"><label class="small fw-bold">WhatsApp</label><input type="text" name="whatsapp" id="edit_whatsapp" class="form-control"></div>
                        <div class="col-6">
                            <label class="small fw-bold">Rol</label>
                            <select name="id_rol" id="edit_rol" class="form-select" required>
                                <?php foreach($roles_db as $r): ?><option value="<?php echo $r['id']; ?>"><?php echo $r['nombre']; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mb-4"><label class="small fw-bold text-danger">Resetear Contraseña</label><input type="password" name="password" class="form-control" placeholder="Vacío para no cambiar"></div>
                    <button type="submit" name="editar_usuario" class="btn btn-dark w-100 fw-bold py-2 rounded-pill">ACTUALIZAR</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    function abrirEditar(user) {
        document.getElementById('edit_id').value = user.id;
        document.getElementById('edit_nombre').value = user.nombre_completo;
        document.getElementById('edit_whatsapp').value = user.whatsapp;
        document.getElementById('edit_rol').value = user.id_rol;
        document.getElementById('edit_username_title').innerText = '@' + user.usuario;
        new bootstrap.Modal(document.getElementById('modalEditar')).show();
    }

    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('msg') === 'creado') Swal.fire({ icon: 'success', title: 'Creado', timer: 1500, showConfirmButton: false });
    if(urlParams.get('msg') === 'editado') Swal.fire({ icon: 'success', title: 'Actualizado', timer: 1500, showConfirmButton: false });
</script>

<?php include 'includes/layout_footer.php'; ?>
