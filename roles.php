<?php
// roles.php - GESTIÓN DE ROLES CON BANNER PRO Y WIDGETS DE ALTURA UNIFICADA
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// SEGURIDAD: Solo SuperAdmin (1) y Dueño (2)
$id_user_sesion = $_SESSION['usuario_id'];
$stmtCheck = $conexion->prepare("SELECT id_rol FROM usuarios WHERE id = ?");
$stmtCheck->execute([$id_user_sesion]);
$rol_usuario_actual = $stmtCheck->fetchColumn();

if($rol_usuario_actual > 2) { header("Location: dashboard.php"); exit; }

// 1. PROCESAR GUARDADO
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_permisos'])) {
    $id_rol = $_POST['id_rol'];
    $permisos_seleccionados = $_POST['permisos'] ?? [];
    try {
        $conexion->beginTransaction();
        $conexion->prepare("DELETE FROM rol_permisos WHERE id_rol = ?")->execute([$id_rol]);
        if (!empty($permisos_seleccionados)) {
            $stmtIns = $conexion->prepare("INSERT INTO rol_permisos (id_rol, id_permiso) VALUES (?, ?)");
            foreach ($permisos_seleccionados as $p_id) { $stmtIns->execute([$id_rol, $p_id]); }
        }
        $conexion->commit();
        header("Location: roles.php?msg=ok"); exit;
    } catch (Exception $e) { $conexion->rollBack(); die("Error: " . $e->getMessage()); }
}

// 2. OBTENER DATOS PARA LOS WIDGETS DEL BANNER
$cantRoles = $conexion->query("SELECT COUNT(*) FROM roles")->fetchColumn();
$cantPermisosTotal = $conexion->query("SELECT COUNT(*) FROM permisos")->fetchColumn();
$cantAsignaciones = $conexion->query("SELECT COUNT(*) FROM rol_permisos")->fetchColumn();

// 3. OBTENER DATOS PARA EL LISTADO
$sqlRoles = "SELECT r.*, (SELECT COUNT(*) FROM rol_permisos rp WHERE rp.id_rol = r.id) as cant_permisos 
             FROM roles r ORDER BY r.id ASC";
$roles = $conexion->query($sqlRoles)->fetchAll(PDO::FETCH_ASSOC);

$sqlPermisos = "SELECT * FROM permisos ORDER BY categoria ASC, clave ASC";
$todos_permisos = $conexion->query($sqlPermisos)->fetchAll(PDO::FETCH_ASSOC);

$permisos_por_cat = [];
foreach ($todos_permisos as $p) { $permisos_por_cat[$p['categoria']][] = $p; }

$asignados = $conexion->query("SELECT id_rol, id_permiso FROM rol_permisos")->fetchAll(PDO::FETCH_ASSOC);
$mapa_permisos = [];
foreach ($asignados as $a) { $mapa_permisos[$a['id_rol']][] = $a['id_permiso']; }
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
    <i class="bi bi-shield-lock-fill bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Gestión de Roles</h2>
                <p class="opacity-75 mb-0 text-white small">Configuración maestra de niveles de acceso y seguridad.</p>
            </div>
            <a href="usuarios.php" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm">
                <i class="bi bi-people-fill me-2"></i> VER PLANTEL
            </a>
        </div>

        <div class="row g-3">
            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Niveles de Rol</div>
                        <div class="widget-value text-white"><?php echo $cantRoles; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white">
                        <i class="bi bi-shield-shaded"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Permisos Totales</div>
                        <div class="widget-value text-white"><?php echo $cantPermisosTotal; ?></div>
                    </div>
                    <div class="icon-box bg-success bg-opacity-20 text-white">
                        <i class="bi bi-key"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Llaves Asignadas</div>
                        <div class="widget-value text-white"><?php echo $cantAsignaciones; ?></div>
                    </div>
                    <div class="icon-box bg-warning bg-opacity-20 text-white">
                        <i class="bi bi-check-all"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget border-info">
                    <div>
                        <div class="widget-label">Estado Sistema</div>
                        <div class="widget-value text-white" style="font-size: 1.1rem;">PROTEGIDO</div>
                    </div>
                    <div class="icon-box bg-info bg-opacity-20 text-white">
                        <i class="bi bi-lock"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <div class="row g-4">
        <?php foreach ($roles as $r): ?>
            <div class="col-md-4">
                <div class="card h-100 card-rol">
                    <span class="badge-count"><i class="bi bi-key-fill me-1"></i> <?php echo $r['cant_permisos']; ?> / <?php echo $cantPermisosTotal; ?></span>
                    <div class="card-body">
                        <div class="d-flex align-items-center mb-3">
                            <div class="icon-box-lg icon-azul me-3" style="width: 50px; height: 50px;"><i class="bi bi-person-badge"></i></div>
                            <div>
                                <h5 class="fw-bold mb-0 text-dark"><?php echo htmlspecialchars($r['nombre']); ?></h5>
                                <small class="text-muted">Nivel #<?php echo $r['id']; ?></small>
                            </div>
                        </div>
                        <p class="small text-secondary mb-4" style="height: 40px; overflow: hidden;"><?php echo htmlspecialchars($r['descripcion']); ?></p>
                        <button onclick="abrirModal(<?php echo $r['id']; ?>, '<?php echo $r['nombre']; ?>')" class="btn btn-outline-primary w-100 rounded-pill fw-bold">
                            <i class="bi bi-gear-wide-connected me-2"></i> CONFIGURAR PERMISOS
                        </button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="modalRoles" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form method="POST" class="modal-content border-0 rounded-4 shadow-lg">
            <div class="modal-header bg-primary text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-shield-check me-2"></i> Editar Permisos: <span id="lblRol"></span></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <input type="hidden" name="id_rol" id="idRolInput">
                <input type="hidden" name="guardar_permisos" value="1">
                
                <div class="d-flex gap-2 mb-4">
                    <button type="button" class="btn btn-sm btn-outline-primary fw-bold px-3" onclick="marcarTodos(true)">MARCAR TODOS</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary fw-bold px-3" onclick="marcarTodos(false)">QUITAR TODOS</button>
                </div>

                <?php foreach ($permisos_por_cat as $categoria => $items): ?>
                    <div class="cat-header"><i class="bi bi-folder2-open me-2"></i> <?php echo $categoria; ?></div>
                    <div class="row g-2 mb-4">
                        <?php foreach ($items as $p): ?>
                            <div class="col-md-4">
                                <div class="permiso-item">
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" name="permisos[]" value="<?php echo $p['id']; ?>" id="chk_<?php echo $p['id']; ?>">
                                        <label class="form-check-label fw-bold d-block" for="chk_<?php echo $p['id']; ?>" style="cursor:pointer; font-size: 0.85rem;">
                                            <?php echo str_replace('_', ' ', strtoupper($p['clave'])); ?>
                                        </label>
                                        <small class="text-muted d-block" style="font-size: 0.7rem; line-height: 1.1;"><?php echo htmlspecialchars($p['descripcion']); ?></small>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="modal-footer border-0 bg-light py-3">
                <button type="button" class="btn btn-secondary rounded-pill px-4" data-bs-modal="hide" data-bs-dismiss="modal">Cerrar</button>
                <button type="submit" class="btn btn-success rounded-pill px-5 fw-bold shadow">GUARDAR CONFIGURACIÓN</button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/layout_footer.php'; ?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    const PERMISOS_ASIGNADOS = <?php echo json_encode($mapa_permisos); ?>;
    function abrirModal(idRol, nombreRol) {
        document.getElementById('lblRol').innerText = nombreRol;
        document.getElementById('idRolInput').value = idRol;
        document.querySelectorAll('input[type=checkbox]').forEach(c => c.checked = false);
        const misPermisos = PERMISOS_ASIGNADOS[idRol] || [];
        misPermisos.forEach(pid => {
            const check = document.getElementById('chk_' + pid);
            if(check) check.checked = true;
        });
        new bootstrap.Modal(document.getElementById('modalRoles')).show();
    }
    function marcarTodos(estado) { document.querySelectorAll('input[name="permisos[]"]').forEach(c => c.checked = estado); }

    if(new URLSearchParams(window.location.search).get('msg') === 'ok') {
        Swal.fire({ icon: 'success', title: '¡Actualizado!', text: 'Los permisos del rol han sido reconfigurados.', timer: 2000, showConfirmButton: false });
    }
</script>