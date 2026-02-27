<?php
// usuarios.php - GESTIÓN DE EQUIPO CON AUTOCOMPLETADO INTELIGENTE
session_start();

$rutas_db = [__DIR__ . '/db.php', __DIR__ . '/includes/db.php', 'db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- MOTOR AJAX: COMPROBADOR DE USUARIOS EN TIEMPO REAL ---
if (isset($_GET['action']) && $_GET['action'] == 'check_user') {
    $base = preg_replace('/[^a-z0-9]/', '', strtolower($_GET['u'])); // Limpiamos caracteres raros
    $user_final = $base;
    $count = 1;
    
    // Buscamos si existe. Si existe, le sumamos 1, 2, 3... hasta que esté libre.
    while (true) {
        $stmt = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = ?");
        $stmt->execute([$user_final]);
        if ($stmt->rowCount() == 0) break; // Está libre!
        $user_final = $base . $count;
        $count++;
    }
    echo $user_final;
    exit; // Cortamos la ejecución acá porque es una petición de JS
}

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);

if (!$es_admin && !in_array('ver_usuarios', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

// --- 1. PROCESAR ALTA ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_usuario'])) {
    if (!$es_admin && !in_array('crear_usuario', $permisos)) die("Sin permiso.");
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) die("Error de seguridad.");

    $nombre = trim($_POST['nombre']);
    $user = trim($_POST['usuario']);
    $email = trim($_POST['email']);
    $whatsapp = trim($_POST['whatsapp']);
    $pass = $_POST['password'];
    $rol = $_POST['id_rol'];

    $check = $conexion->prepare("SELECT id FROM usuarios WHERE usuario = ?");
    $check->execute([$user]);
    
    if ($check->rowCount() > 0) {
        header("Location: usuarios.php?err=duplicado"); exit;
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $sql = "INSERT INTO usuarios (nombre_completo, usuario, email, whatsapp, password, id_rol, activo) VALUES (?, ?, ?, ?, ?, ?, 1)";
        if ($conexion->prepare($sql)->execute([$nombre, $user, $email, $whatsapp, $hash, $rol])) {
            $d_aud = "Usuario Creado: " . $user . " (" . $nombre . ")"; 
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'USUARIO_NUEVO', ?, NOW())")->execute([$_SESSION['usuario_id'], $d_aud]);
            header("Location: usuarios.php?msg=creado"); exit;
        }
    }
}

// --- 2. PROCESAR EDICIÓN ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['editar_usuario'])) {
    if (!$es_admin && !in_array('editar_usuario', $permisos)) die("Sin permiso.");
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) die("Error de seguridad.");

    $id_upd = intval($_POST['id_usuario_edit']);
    $nombre = trim($_POST['nombre']);
    $email = trim($_POST['email']);
    $whatsapp = trim($_POST['whatsapp']);
    $rol = intval($_POST['id_rol']);

    $stmtOld = $conexion->prepare("SELECT nombre_completo, id_rol, whatsapp, email FROM usuarios WHERE id = ?");
    $stmtOld->execute([$id_upd]);
    $old = $stmtOld->fetch(PDO::FETCH_ASSOC);
    
    $cambios = [];
    if($old['nombre_completo'] != $nombre) $cambios[] = "Nombre: " . $old['nombre_completo'] . " -> " . $nombre;
    if($old['email'] != $email) $cambios[] = "Email: " . ($old['email']?:'-') . " -> " . ($email?:'-');
    if($old['whatsapp'] != $whatsapp) $cambios[] = "WA: " . ($old['whatsapp']?:'-') . " -> " . ($whatsapp?:'-');
    if($old['id_rol'] != $rol) {
        $nRoles = $conexion->query("SELECT id, nombre FROM roles WHERE id IN (".$old['id_rol'].", ".$rol.")")->fetchAll(PDO::FETCH_KEY_PAIR);
        $cambios[] = "Rol: " . ($nRoles[$old['id_rol']]??'N/A') . " -> " . ($nRoles[$rol]??'N/A');
    }

    $sql = "UPDATE usuarios SET nombre_completo=?, email=?, whatsapp=?, id_rol=? WHERE id=?";
    $params = [$nombre, $email, $whatsapp, $rol, $id_upd];
    
    if(!empty($_POST['password'])) {
        $hash = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $sql = "UPDATE usuarios SET nombre_completo=?, email=?, whatsapp=?, id_rol=?, password=? WHERE id=?";
        $params = [$nombre, $email, $whatsapp, $rol, $hash, $id_upd];
        $cambios[] = "Contraseña reseteada";
    }
    $conexion->prepare($sql)->execute($params);

    if(!empty($cambios)) {
        $d_aud = "Usuario Editado ID $id_upd | " . implode(" | ", $cambios);
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'USUARIO_EDITADO', ?, NOW())")->execute([$_SESSION['usuario_id'], $d_aud]);
    }
    header("Location: usuarios.php?msg=editado"); exit;
}

// --- 3. PROCESAR ELIMINACIÓN ---
if (isset($_GET['borrar'])) {
    if (!$es_admin && !in_array('eliminar_usuario', $permisos)) die("Sin permiso.");
    $id_borrar = intval($_GET['borrar']);
    
    if ($id_borrar == $_SESSION['usuario_id'] || $id_borrar == 1) {
        $err = ($id_borrar == 1) ? "admin" : "self";
        header("Location: usuarios.php?err=$err"); exit;
    }

    $conexion->prepare("DELETE FROM usuarios WHERE id = ?")->execute([$id_borrar]);
    $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'USUARIO_ELIMINADO', ?, NOW())")->execute([$_SESSION['usuario_id'], "Usuario ID $id_borrar Eliminado."]);
    header("Location: usuarios.php?msg=eliminado"); exit;
}

// --- 4. DATOS DE LA TABLA ---
$total_users = $conexion->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();
$total_activos = $conexion->query("SELECT COUNT(*) FROM usuarios WHERE activo = 1")->fetchColumn();
$usuarios = $conexion->query("SELECT u.*, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id ORDER BY u.id_rol ASC")->fetchAll(PDO::FETCH_ASSOC);
$roles_db = $conexion->query("SELECT * FROM roles")->fetchAll(PDO::FETCH_ASSOC);

$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1");
    if ($resColor && $dataC = $resColor->fetch(PDO::FETCH_ASSOC)) {
        if (isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav'];
    }
} catch (Exception $e) { }
?>

<?php include 'includes/layout_header.php'; ?>

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden;">
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
                <?php if($es_admin || in_array('crear_usuario', $permisos)): ?>
                    <button class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalAlta">
                        <i class="bi bi-person-plus-fill me-2"></i> NUEVO USUARIO
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-6 col-md-3"><div class="header-widget"><div><div class="widget-label">Total Equipo</div><div class="widget-value text-white"><?php echo $total_users; ?></div></div><div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-person-badge"></i></div></div></div>
            <div class="col-6 col-md-3"><div class="header-widget"><div><div class="widget-label">Miembros Activos</div><div class="widget-value text-white"><?php echo $total_activos; ?></div></div><div class="icon-box bg-success bg-opacity-20 text-white"><i class="bi bi-check-all"></i></div></div></div>
            <div class="col-6 col-md-3"><div class="header-widget"><div><div class="widget-label">Niveles de Rango</div><div class="widget-value text-white"><?php echo count($roles_db); ?></div></div><div class="icon-box bg-warning bg-opacity-20 text-white"><i class="bi bi-award"></i></div></div></div>
            <div class="col-6 col-md-3"><div class="header-widget border-info"><div><div class="widget-label">Estado de Red</div><div class="widget-value text-white" style="font-size: 1.1rem;">CONECTADO</div></div><div class="icon-box bg-info bg-opacity-20 text-white"><i class="bi bi-broadcast"></i></div></div></div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <?php if(isset($_GET['err'])): ?>
        <div class='alert alert-warning border-0 shadow-sm fw-bold mb-3 small'>
            <i class='bi bi-exclamation-triangle me-2'></i>
            <?php 
                if($_GET['err'] == 'self') echo "No puedes eliminar tu propia cuenta."; 
                elseif($_GET['err'] == 'admin') echo "El SuperAdmin no puede ser eliminado.";
                elseif($_GET['err'] == 'duplicado') echo "Ese nombre de usuario ya existe en el sistema. Elegí otro.";
            ?>
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
                            $data_json = htmlspecialchars(json_encode($user), ENT_QUOTES, 'UTF-8');
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
                                <div class="small fw-bold"><i class="bi bi-whatsapp text-success me-1"></i><?php echo htmlspecialchars($user['whatsapp'] ?: '-'); ?></div>
                                <div class="text-muted small"><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($user['email'] ?: 'Sin email'); ?></div>
                            </td>
                            <td>
                                <span class="fw-bold small <?php echo $user['activo'] == 1 ? 'text-success' : 'text-muted'; ?>">
                                    <i class="bi bi-circle-fill me-1" style="font-size: 0.5rem;"></i> <?php echo $user['activo'] == 1 ? 'Activo' : 'Inactivo'; ?>
                                </span>
                            </td>
                            <td class="text-end pe-4">
                                <div class="d-flex justify-content-end gap-2">
                                    <?php if($es_admin || in_array('editar_usuario', $permisos)): ?>
                                        <button onclick="abrirEditar(<?php echo $data_json; ?>)" class="btn btn-sm btn-light text-warning rounded-circle" title="Editar">
                                            <i class="bi bi-pencil-fill"></i>
                                        </button>
                                    <?php endif; ?>

                                    <?php if($es_admin || in_array('eliminar_usuario', $permisos)): ?>
                                        <a href="usuarios.php?borrar=<?php echo $user['id']; ?>" class="btn btn-sm btn-light text-danger rounded-circle" onclick="confirmarEliminacion(event, this.href)">
                                            <i class="bi bi-trash-fill"></i>
                                        </a>
                                    <?php endif; ?>
                                </div>
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
                <h6 class="modal-title fw-bold"><i class="bi bi-person-plus-fill me-2"></i> NUEVO INTEGRANTE</h6>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                    
                    <div class="mb-3">
                        <label class="small fw-bold">Nombre y Apellido</label>
                        <input type="text" name="nombre" id="alta_nombre" class="form-control" placeholder="Ej: Federico González" required>
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="small fw-bold">Usuario <small class="text-muted">(Editable)</small></label>
                            <input type="text" name="usuario" id="alta_usuario" class="form-control" required>
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold">Contraseña</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="small fw-bold">Correo Electrónico</label>
                            <input type="email" name="email" class="form-control" placeholder="ejemplo@correo.com">
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold">Teléfono / WhatsApp</label>
                            <input type="text" name="whatsapp" class="form-control" placeholder="+54 9...">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="small fw-bold">Rol en el Sistema</label>
                        <select name="id_rol" class="form-select" required>
                            <option value="">Seleccione un rol...</option>
                            <?php foreach($roles_db as $r): ?>
                                <option value="<?php echo $r['id']; ?>"><?php echo $r['nombre']; ?></option>
                            <?php endforeach; ?>
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
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                    <input type="hidden" name="id_usuario_edit" id="edit_id">
                    
                    <div class="mb-3">
                        <label class="small fw-bold">Nombre y Apellido</label>
                        <input type="text" name="nombre" id="edit_nombre" class="form-control" required>
                    </div>
                    
                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="small fw-bold text-muted">Usuario <small>(No se puede cambiar)</small></label>
                            <input type="text" name="usuario" id="edit_usuario" class="form-control bg-light" readonly>
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold text-danger">Resetear Contraseña</label>
                            <input type="password" name="password" class="form-control" placeholder="Dejar vacío para no cambiar">
                        </div>
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="small fw-bold">Correo Electrónico</label>
                            <input type="email" name="email" id="edit_email" class="form-control">
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold">Teléfono / WhatsApp</label>
                            <input type="text" name="whatsapp" id="edit_whatsapp" class="form-control">
                        </div>
                    </div>

                    <div class="mb-4">
                        <label class="small fw-bold">Rol en el Sistema</label>
                        <select name="id_rol" id="edit_rol" class="form-select" required>
                            <?php foreach($roles_db as $r): ?>
                                <option value="<?php echo $r['id']; ?>"><?php echo $r['nombre']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <button type="submit" name="editar_usuario" class="btn btn-dark w-100 fw-bold py-2 rounded-pill">GUARDAR CAMBIOS</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // --- LÓGICA DE AUTOCOMPLETADO DE USUARIO ---
    document.getElementById('alta_nombre').addEventListener('blur', function() {
        let nombreCompleto = this.value.trim();
        if(nombreCompleto.length < 3) return;

        // Limpiar acentos para armar el base (Federico González -> fgonzalez)
        let limpio = nombreCompleto.normalize("NFD").replace(/[\u0300-\u036f]/g, "");
        let partes = limpio.split(/\s+/);
        
        let baseUser = "";
        if(partes.length >= 2) {
            baseUser = partes[0].charAt(0) + partes[1]; // Primera letra del nombre + apellido
        } else {
            baseUser = partes[0]; // Si pone un solo nombre, usamos ese
        }

        // Consultamos al servidor si existe y le pedimos el número
        if(baseUser !== "") {
            fetch('usuarios.php?action=check_user&u=' + encodeURIComponent(baseUser))
            .then(r => r.text())
            .then(userLibre => {
                document.getElementById('alta_usuario').value = userLibre;
            });
        }
    });

    // --- LÓGICA DEL MODAL EDITAR ---
    function abrirEditar(user) {
        document.getElementById('edit_id').value = user.id;
        document.getElementById('edit_nombre').value = user.nombre_completo;
        document.getElementById('edit_usuario').value = user.usuario;
        document.getElementById('edit_email').value = user.email || '';
        document.getElementById('edit_whatsapp').value = user.whatsapp || '';
        document.getElementById('edit_rol').value = user.id_rol;
        
        let modalEdit = bootstrap.Modal.getOrCreateInstance(document.getElementById('modalEditar'));
        modalEdit.show();
    }

    // --- LÓGICA ELIMINAR ---
    function confirmarEliminacion(e, url) {
        e.preventDefault();
        Swal.fire({
            title: '¿Estás seguro?',
            text: "Esta acción borrará al usuario permanentemente.",
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

    // --- ALERTAS ---
    const urlParams = new URLSearchParams(window.location.search);
    if(urlParams.get('msg') === 'creado') Swal.fire({ icon: 'success', title: 'Usuario Creado', timer: 1500, showConfirmButton: false });
    if(urlParams.get('msg') === 'editado') Swal.fire({ icon: 'success', title: 'Usuario Actualizado', timer: 1500, showConfirmButton: false });
    if(urlParams.get('msg') === 'eliminado') Swal.fire({ icon: 'success', title: 'Usuario Eliminado', timer: 1500, showConfirmButton: false });
</script>

<?php include 'includes/layout_footer.php'; ?>