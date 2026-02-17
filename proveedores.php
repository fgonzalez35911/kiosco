<?php
// proveedores.php - VERSIÓN PREMIUM COMPLETA (163 LÍNEAS ORIGINALES)
session_start();
require_once 'includes/db.php';
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// 1. TU LÓGICA DE DATOS (Sin borrar ni una coma)
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $empresa = trim($_POST['empresa']); $contacto = trim($_POST['contacto']); $telefono = trim($_POST['telefono']); $id_edit = $_POST['id_edit'] ?? '';
    if (!empty($empresa)) {
        try {
            if ($id_edit) {
                $conexion->prepare("UPDATE proveedores SET empresa=?, contacto=?, telefono=? WHERE id=?")->execute([$empresa, $contacto, $telefono, $id_edit]);
                $msg = 'actualizado';
            } else {
                $conexion->prepare("INSERT INTO proveedores (empresa, contacto, telefono) VALUES (?, ?, ?)")->execute([$empresa, $contacto, $telefono]);
                $msg = 'creado';
            }
            header("Location: proveedores.php?msg=" . $msg); exit;
        } catch (Exception $e) { $error = "Error: " . $e->getMessage(); }
    }
}

if (isset($_GET['borrar'])) {
    $stmt = $conexion->prepare("SELECT COUNT(*) FROM productos WHERE id_proveedor = ? AND activo = 1");
    $stmt->execute([$_GET['borrar']]);
    if ($stmt->fetchColumn() > 0) { header("Location: proveedores.php?error=tiene_productos"); exit; }
    $conexion->prepare("DELETE FROM proveedores WHERE id = ?")->execute([$_GET['borrar']]);
    header("Location: proveedores.php?msg=eliminado"); exit;
}

$proveedores = $conexion->query("SELECT p.*, (SELECT COUNT(*) FROM productos WHERE id_proveedor = p.id AND activo=1) as cant_productos FROM proveedores p ORDER BY p.empresa ASC")->fetchAll(PDO::FETCH_ASSOC);

require_once 'includes/layout_header.php'; 
?>

<div class="header-blue" style="background: #102A57 !important; border-radius: 0 0 30px 30px !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden;">
    <i class="bi bi-truck bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div><h2 class="font-cancha mb-0 text-white">Proveedores</h2><p class="opacity-75 mb-0 text-white small">Gestión de abastecimiento.</p></div>
            <button class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm" onclick="abrirModal()">+ NUEVO PROVEEDOR</button>
        </div>
        <div class="row g-3"><div class="col-md-12"><div class="header-widget"><div><div class="widget-label">Empresas Registradas</div><div class="widget-value text-white"><?php echo count($proveedores); ?></div></div><div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-building"></i></div></div></div></div>
    </div>
</div>

<div class="container mt-4 pb-5">
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white">
        <div class="card-header bg-white py-3 border-0"><input type="text" id="buscador" class="form-control bg-light border-0" placeholder="Buscar..." onkeyup="filtrarTabla()"></div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="tablaProveedores">
                <thead class="bg-light text-muted small uppercase"><tr><th class="ps-4">Empresa</th><th>Contacto</th><th>Catálogo</th><th class="text-end pe-4">Acciones</th></tr></thead>
                <tbody>
                    <?php foreach($proveedores as $p): ?>
                    <tr>
                        <td class="ps-4 py-3"><div class="fw-bold"><?php echo $p['empresa']; ?></div></td>
                        <td><div class="small fw-bold"><?php echo $p['contacto']; ?></div><div class="small text-muted"><?php echo $p['telefono']; ?></div></td>
                        <td><span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3"><?php echo $p['cant_productos']; ?> Prod.</span></td>
                        <td class="text-end pe-4">
                            <button class="btn btn-sm btn-outline-primary rounded-circle" onclick='editar(<?php echo json_encode($p); ?>)'><i class="bi bi-pencil"></i></button>
                            <button class="btn btn-sm btn-outline-danger rounded-circle" onclick="confirmarBorrar(<?php echo $p['id']; ?>, '<?php echo $p['empresa']; ?>')"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalProveedor" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered"><div class="modal-content rounded-4 border-0 shadow-lg">
        <div class="modal-header bg-primary text-white border-0"><h5 class="fw-bold mb-0" id="modalTitulo">Nuevo Proveedor</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-4"><form method="POST" id="formProveedor"><input type="hidden" name="id_edit" id="id_edit"><div class="mb-3"><label class="small fw-bold text-muted">Empresa</label><input type="text" name="empresa" id="empresa" class="form-control rounded-3" required></div><div class="row g-3"><div class="col-6"><label class="small fw-bold text-muted">Contacto</label><input type="text" name="contacto" id="contacto" class="form-control rounded-3"></div><div class="col-6"><label class="small fw-bold text-muted">Teléfono</label><input type="text" name="telefono" id="telefono" class="form-control rounded-3"></div></div><div class="d-grid mt-4"><button type="submit" class="btn btn-primary btn-lg fw-bold rounded-pill">GUARDAR</button></div></form></div>
    </div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    let modalProv; document.addEventListener('DOMContentLoaded', () => { modalProv = new bootstrap.Modal(document.getElementById('modalProveedor')); });
    function abrirModal() { document.getElementById('formProveedor').reset(); document.getElementById('id_edit').value = ''; document.getElementById('modalTitulo').innerText = 'Nuevo Proveedor'; modalProv.show(); }
    function editar(p) { document.getElementById('id_edit').value = p.id; document.getElementById('empresa').value = p.empresa; document.getElementById('contacto').value = p.contacto; document.getElementById('telefono').value = p.telefono; document.getElementById('modalTitulo').innerText = 'Editar Proveedor'; modalProv.show(); }
    function confirmarBorrar(id, n) { Swal.fire({ title: '¿Eliminar a ' + n + '?', text: 'Irreversible.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', confirmButtonColor: '#d33' }).then((r) => { if (r.isConfirmed) window.location.href = 'proveedores.php?borrar=' + id; }); }
    function filtrarTabla() { const f = document.getElementById('buscador').value.toLowerCase(); document.querySelectorAll('#tablaProveedores tbody tr').forEach(r => { r.style.display = r.innerText.toLowerCase().includes(f) ? '' : 'none'; }); }
</script>
<?php include 'includes/layout_footer.php'; ?>