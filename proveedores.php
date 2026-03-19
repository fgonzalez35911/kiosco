<?php
// proveedores.php - VERSIÓN VANGUARD PRO TOTAL (ESTANDARIZADO + PEDIDO SUGERIDO)
session_start();
require_once 'includes/db.php';
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$permisos = $_SESSION['permisos'] ?? [];
$es_admin = ($_SESSION['rol'] <= 2);

if (!$es_admin && !in_array('proveedores_ver', $permisos)) {
    header("Location: dashboard.php"); exit; 
}

$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

// --- LÓGICA AJAX PARA PEDIDO SUGERIDO (NUEVA FUNCIÓN) ---
if (isset($_GET['ajax_sugerido'])) {
    header('Content-Type: application/json');
    $id_prov = intval($_GET['ajax_sugerido']);
    // Busca productos de este proveedor cuyo stock esté por debajo del mínimo, o si no hay mínimo, menor a 5.
    $stmt = $conexion->prepare("SELECT id, descripcion, stock_actual, stock_minimo FROM productos WHERE id_proveedor = ? AND activo = 1 AND (stock_actual <= stock_minimo OR stock_actual < 5) AND (tipo_negocio = ? OR tipo_negocio IS NULL) ORDER BY descripcion ASC");
    $stmt->execute([$id_prov, $rubro_actual]);
    $prods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($prods);
    exit;
}
// --------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['datos_pedido'])) {
    $empresa = trim($_POST['empresa']); 
    $contacto = trim($_POST['contacto']); 
    $cod_pais = trim($_POST['cod_pais'] ?? '54');
    $tel_numero = trim($_POST['telefono']);
    $telefono_final = !empty($tel_numero) ? '+' . $cod_pais . $tel_numero : '';
    $email = trim($_POST['email'] ?? ''); 
    $id_edit = $_POST['id_edit'] ?? '';
    
    if ($id_edit && !$es_admin && !in_array('proveedores_editar', $permisos)) die("Error: Sin permiso para editar.");
    if (!$id_edit && !$es_admin && !in_array('proveedores_crear', $permisos)) die("Error: Sin permiso para crear.");

    if (!empty($empresa)) {
        try {
            if ($id_edit) {
                $stmtOld = $conexion->prepare("SELECT empresa, contacto, telefono, email FROM proveedores WHERE id = ?");
                $stmtOld->execute([$id_edit]);
                $old = $stmtOld->fetch(PDO::FETCH_ASSOC);

                $cambios = [];
                if($old['empresa'] != $empresa) $cambios[] = "Empresa: " . $old['empresa'] . " -> " . $empresa;
                if($old['contacto'] != $contacto) $cambios[] = "Contacto: " . ($old['contacto']?:'-') . " -> " . ($contacto?:'-');
                if($old['telefono'] != $telefono_final) $cambios[] = "Tel: " . ($old['telefono']?:'-') . " -> " . ($telefono_final?:'-');
                if($old['email'] != $email) $cambios[] = "Email: " . ($old['email']?:'-') . " -> " . ($email?:'-');

                $conexion->prepare("UPDATE proveedores SET empresa=?, contacto=?, telefono=?, email=? WHERE id=?")->execute([$empresa, $contacto, $telefono_final, $email, $id_edit]);
                
                if(!empty($cambios)) {
                    $d_aud = "Proveedor Editado: " . $empresa . " | " . implode(" | ", $cambios);
                    $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'PROVEEDOR_EDITADO', ?, NOW())")->execute([$_SESSION['usuario_id'], $d_aud]);
                }
                header("Location: proveedores.php?msg=actualizado"); exit;
            } else {
                $conexion->prepare("INSERT INTO proveedores (empresa, contacto, telefono, email, tipo_negocio) VALUES (?, ?, ?, ?, ?)")->execute([$empresa, $contacto, $telefono_final, $email, $rubro_actual]);
                $d_aud = "Proveedor Nuevo: " . $empresa; 
                $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'PROVEEDOR_NUEVO', ?, NOW())")->execute([$_SESSION['usuario_id'], $d_aud]);
                header("Location: proveedores.php?msg=creado"); exit;
            }
        } catch (Exception $e) { $error = "Error: " . $e->getMessage(); }
    }
}

if (isset($_GET['borrar'])) {
    if (!$es_admin && !in_array('proveedores_eliminar', $permisos)) die("Error: Sin permiso para eliminar.");
    
    $stmt = $conexion->prepare("SELECT COUNT(*) FROM productos WHERE id_proveedor = ? AND activo = 1");
    $stmt->execute([$_GET['borrar']]);
    if ($stmt->fetchColumn() > 0) { header("Location: proveedores.php?error=tiene_productos"); exit; }
    
    $conexion->prepare("DELETE FROM proveedores WHERE id = ?")->execute([$_GET['borrar']]);
    $d_aud = "Proveedor Eliminado (ID: " . $_GET['borrar'] . ")"; 
    $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'PROVEEDOR_ELIMINADO', ?, NOW())")->execute([$_SESSION['usuario_id'], $d_aud]);
    header("Location: proveedores.php?msg=eliminado"); exit;
}

$proveedores = $conexion->query("SELECT p.*, (SELECT COUNT(*) FROM productos WHERE id_proveedor = p.id AND activo=1 AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)) as cant_productos FROM proveedores p WHERE (p.tipo_negocio = '$rubro_actual' OR p.tipo_negocio IS NULL) ORDER BY p.empresa ASC")->fetchAll(PDO::FETCH_ASSOC);
$deuda_global = $conexion->query("SELECT SUM(CASE WHEN tipo = 'compra' THEN monto ELSE -monto END) FROM movimientos_proveedores WHERE (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL)")->fetchColumn() ?: 0;

$conf_color_sis = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf_color_sis['color_barra_nav'] ?? '#102A57';
require_once 'includes/layout_header.php';

// --- BANNER DINÁMICO ESTANDARIZADO ---
$titulo = "Mis Proveedores";
$subtitulo = "Gestión de abastecimiento, saldos y pedidos automáticos.";
$icono_bg = "bi-truck";
$botones = [
    ['texto' => 'REPORTE PDF', 'link' => "reporte_proveedores.php", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-4 shadow-sm', 'target' => '_blank']
];

if($es_admin || in_array('proveedores_crear', $permisos)){
    $botones[] = ['texto' => 'NUEVO PROVEEDOR', 'link' => 'javascript:abrirModal()', 'icono' => 'bi-plus-lg', 'class' => 'btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm ms-2'];
}

$widgets = [
    ['label' => 'Registrados', 'valor' => count($proveedores), 'icono' => 'bi-building', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Deuda Global', 'valor' => '$'.number_format($deuda_global, 0, ',', '.'), 'icono' => 'bi-cash-stack', 'border' => 'border-danger', 'icon_bg' => 'bg-danger bg-opacity-20']
];

if($es_admin || in_array('importacion_masiva', $permisos)){
    $widgets[] = ['label' => 'Acciones', 'valor' => 'IMPORTAR', 'icono' => 'bi-file-earmark-arrow-up', 'icon_bg' => 'bg-warning bg-opacity-20', 'link' => 'importador_maestro.php', 'text_color' => 'text-warning'];
}

include 'includes/componente_banner.php'; 
?>

<div class="container-fluid container-md mt-n4 px-3" style="position: relative; z-index: 20;">
    <div class="card border-0 shadow-sm rounded-4 overflow-hidden bg-white mb-5">
        <div class="card-header bg-white py-3 border-0">
            <input type="text" id="buscador" class="form-control bg-light border-0 fw-bold shadow-none" placeholder="Buscar por empresa o contacto..." onkeyup="filtrarTabla()">
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0" id="tablaProveedores">
                <thead class="bg-light text-muted small text-uppercase fw-bold">
                    <tr><th class="ps-4">Empresa</th><th>Contacto / Teléfono</th><th class="text-center d-none d-md-table-cell">Stock</th><th class="text-end pe-4">Acciones</th></tr>
                </thead>
                <tbody>
                    <?php foreach($proveedores as $p): ?>
                    <tr>
                        <td class="ps-4 py-3"><div class="fw-bold text-dark fs-6"><?php echo $p['empresa']; ?></div><small class="text-muted">ID #<?php echo $p['id']; ?></small></td>
                        <td>
                            <div class="small fw-bold text-dark"><?php echo $p['contacto'] ?: 'Sin contacto'; ?></div>
                            <div class="small text-muted"><i class="bi bi-whatsapp me-1"></i><?php echo $p['telefono'] ?: '-'; ?></div>
                            <?php if(!empty($p['email'])): ?><div class="small text-primary"><i class="bi bi-envelope"></i> <?php echo $p['email']; ?></div><?php endif; ?>
                        </td>
                        <td class="text-center d-none d-md-table-cell"><span class="badge bg-success bg-opacity-10 text-success rounded-pill px-3"><?php echo $p['cant_productos']; ?> SKU</span></td>
                        <td class="text-end pe-4 text-nowrap">
                            <?php if($es_admin || in_array('proveedores_ver_cc', $permisos)): ?>
                            <a href="cuenta_proveedor.php?id=<?php echo $p['id']; ?>" class="btn btn-sm btn-dark rounded-pill px-2 px-md-3 me-1 shadow-sm" title="Ver Cuenta">
                                <i class="bi bi-receipt"></i> <span class="d-none d-md-inline">CTA. CTE.</span>
                            </a>
                            <?php endif; ?>

                            <button class="btn btn-sm btn-success rounded-circle me-1 shadow-sm" onclick="armarPedido(<?php echo $p['id']; ?>, '<?php echo addslashes($p['empresa']); ?>')" title="Generar Pedido Sugerido">
                                <i class="bi bi-cart-plus-fill"></i>
                            </button>

                            <?php if($es_admin || in_array('proveedores_editar', $permisos)): ?>
                            <button class="btn btn-sm btn-outline-primary rounded-circle me-1"
                                    data-id="<?php echo $p['id']; ?>"
                                    data-empresa="<?php echo htmlspecialchars($p['empresa']); ?>"
                                    data-contacto="<?php echo htmlspecialchars($p['contacto']); ?>"
                                    data-telefono="<?php echo htmlspecialchars($p['telefono'] ?? ''); ?>"
                                    data-email="<?php echo htmlspecialchars($p['email'] ?? ''); ?>"
                                    onclick="editarProv(this)" title="Editar">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php endif; ?>

                            <?php if($es_admin || in_array('proveedores_eliminar', $permisos)): ?>
                            <button class="btn btn-sm btn-outline-danger rounded-circle" onclick="confirmarBorrar(<?php echo $p['id']; ?>, '<?php echo addslashes($p['empresa']); ?>')" title="Eliminar"><i class="bi bi-trash"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalProveedor" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content rounded-4 border-0 shadow-lg">
            <div class="modal-header bg-primary text-white border-0"><h5 class="fw-bold mb-0" id="modalTitulo">Nuevo Proveedor</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
            <div class="modal-body p-4">
                <form method="POST" id="formProveedor">
                    <input type="hidden" name="id_edit" id="id_edit">
                    <div class="mb-3"><label class="small fw-bold text-muted">Empresa</label><input type="text" name="empresa" id="empresa" class="form-control rounded-3" required></div>
                    <div class="row g-3">
                        <div class="col-12 col-md-6"><label class="small fw-bold text-muted">Contacto</label><input type="text" name="contacto" id="contacto" class="form-control rounded-3"></div>
                        
                        <div class="col-12 col-md-6">
                            <label class="small fw-bold text-muted">Teléfono (WhatsApp)</label>
                            <div class="input-group">
                                <select name="cod_pais" id="cod_pais" class="form-select bg-light border-end-0 fw-bold" style="max-width: 95px;" required>
                                    <option value="54" data-iso="ar" data-name="ARG">+54</option>
                                    <option value="598" data-iso="uy" data-name="URY">+598</option>
                                    <option value="595" data-iso="py" data-name="PRY">+595</option>
                                    <option value="56" data-iso="cl" data-name="CHL">+56</option>
                                    <option value="591" data-iso="bo" data-name="BOL">+591</option>
                                    <option value="55" data-iso="br" data-name="BRA">+55</option>
                                    <option value="51" data-iso="pe" data-name="PER">+51</option>
                                    <option value="57" data-iso="co" data-name="COL">+57</option>
                                    <option value="52" data-iso="mx" data-name="MEX">+52</option>
                                    <option value="34" data-iso="es" data-name="ESP">+34</option>
                                    <option value="1" data-iso="us" data-name="USA">+1</option>
                                </select>
                                <input type="number" name="telefono" id="telefono" class="form-control px-2" placeholder="1123456789">
                            </div>
                        </div>

                        <div class="col-12 mt-2"><label class="small fw-bold text-muted">Correo Electrónico (Tickets)</label><input type="email" name="email" id="email" class="form-control rounded-3" placeholder="proveedor@ejemplo.com"></div>
                    </div>
                    <div class="d-grid mt-4"><button type="submit" class="btn btn-primary btn-lg fw-bold rounded-pill">GUARDAR CAMBIOS</button></div>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    let modalProv; 
    document.addEventListener('DOMContentLoaded', () => { modalProv = new bootstrap.Modal(document.getElementById('modalProveedor')); });
    
    function abrirModal() { 
        document.getElementById('formProveedor').reset(); 
        document.getElementById('id_edit').value = ''; 
        document.getElementById('modalTitulo').innerText = 'Nuevo Proveedor'; 
        document.getElementById('cod_pais').value = '54'; 
        modalProv.show(); 
    }
    
    function editarProv(btn) { 
        document.getElementById('id_edit').value = btn.getAttribute('data-id'); 
        document.getElementById('empresa').value = btn.getAttribute('data-empresa'); 
        document.getElementById('contacto').value = btn.getAttribute('data-contacto'); 
        
        let t = btn.getAttribute('data-telefono') || '';
        let cod = '54'; let num = t;
        if(t.startsWith('+')) {
            const codes = ['54', '598', '595', '56', '591', '55', '51', '57', '52', '34', '1'];
            for(let c of codes) {
                if(t.startsWith('+' + c)) { cod = c; num = t.substring(c.length + 1); break; }
            }
        } else {
            num = t.replace(/[^0-9]/g, '');
        }
        
        document.getElementById('cod_pais').value = cod;
        document.getElementById('telefono').value = num;
        document.getElementById('email').value = btn.getAttribute('data-email'); 
        document.getElementById('modalTitulo').innerText = 'Editar Proveedor'; 
        modalProv.show(); 
    }

    // --- FUNCIÓN MAGIA: ARMAR PEDIDO SUGERIDO ---
    function armarPedido(id_prov, empresa) {
        Swal.fire({
            title: 'Analizando stock...',
            allowOutsideClick: false,
            didOpen: () => { Swal.showLoading(); }
        });
        
        fetch('proveedores.php?ajax_sugerido=' + id_prov)
        .then(r => r.json())
        .then(data => {
            if(data.length === 0) {
                Swal.fire({ icon: 'success', title: 'Stock Óptimo', text: 'No hay productos de ' + empresa + ' que necesiten reposición.', confirmButtonColor: '#198754' });
                return;
            }
            
            let html = '<div class="table-responsive text-start"><table class="table table-sm align-middle"><thead class="table-light"><tr><th style="font-size:12px;">Producto</th><th style="font-size:12px;" class="text-center">Stock</th><th style="font-size:12px; width:90px;">Pedir</th></tr></thead><tbody>';
            data.forEach(p => {
                // Cálculo inteligente de sugerencia (El doble del mínimo, o 5 por defecto)
                let sug = (p.stock_minimo > 0) ? (p.stock_minimo * 2) - p.stock_actual : 5;
                if(sug < 1) sug = 1;
                html += `<tr>
                    <td class="small fw-bold lh-sm">${p.descripcion}</td>
                    <td class="text-center"><span class="badge ${p.stock_actual <= 0 ? 'bg-danger' : 'bg-warning text-dark'}">${p.stock_actual}</span></td>
                    <td><input type="number" class="form-control form-control-sm text-center fw-bold input-pedir" data-desc="${p.descripcion}" value="${sug}" min="0"></td>
                </tr>`;
            });
            html += '</tbody></table></div>';
            html += `
            <div class="text-start mt-2 px-3 py-2 rounded-3 shadow-sm" style="background-color: #f0f7ff; border: 1px solid #cce3f6;">
                <div class="fw-bold text-primary mb-1" style="font-size: 13px;"><i class="bi bi-robot"></i> Asistente Inteligente de Reposición</div>
                <div style="font-size: 11.5px; color: #444; line-height: 1.4;">
                    <strong>¿En qué se basa esta sugerencia?</strong> El sistema solo te muestra los productos que cayeron por debajo de su "Stock Mínimo". Para asegurar que no te quedes sin mercadería, calcula la cantidad necesaria para <strong>duplicar tu stock mínimo</strong>, restando lo que ya tenés en la estantería.<br>
                    <div class="text-center my-2"><code class="bg-white border px-2 py-1 text-dark rounded shadow-sm">Fórmula: (Stock Mínimo x 2) - Stock Actual</code></div>
                    <span class="text-muted"><i class="bi bi-info-circle"></i> <em>Modificá las cantidades a tu gusto. Lo que dejes en 0 no se incluirá en el reporte PDF. (Si no configuraste un stock mínimo para un producto, se sugieren 5 unidades por precaución).</em></span>
                </div>
            </div>`;
            
            Swal.fire({
                title: '<i class="bi bi-cart-check text-success"></i> Pedido a ' + empresa,
                html: html,
                width: '600px',
                showCancelButton: true,
                confirmButtonText: '<i class="bi bi-printer-fill"></i> Imprimir Reporte',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#102A57',
                preConfirm: () => {
                    let pedido = [];
                    document.querySelectorAll('.input-pedir').forEach(inp => {
                        let c = parseInt(inp.value);
                        if(c > 0) pedido.push({ desc: inp.getAttribute('data-desc'), cant: c });
                    });
                    if(pedido.length === 0) {
                        Swal.showValidationMessage('Debes incluir al menos un producto para pedir.');
                        return false;
                    }
                    return pedido;
                }
            }).then((res) => {
                if(res.isConfirmed) {
                    // Enviar datos por POST al PDF en una pestaña nueva
                    let form = document.createElement('form');
                    form.method = 'POST';
                    form.action = 'reporte_pedido_proveedor.php';
                    form.target = '_blank';
                    
                    let inpData = document.createElement('input');
                    inpData.type = 'hidden';
                    inpData.name = 'datos';
                    inpData.value = JSON.stringify(res.value);
                    form.appendChild(inpData);
                    
                    let inpEmpresa = document.createElement('input');
                    inpEmpresa.type = 'hidden';
                    inpEmpresa.name = 'empresa';
                    inpEmpresa.value = empresa;
                    form.appendChild(inpEmpresa);
                    
                    document.body.appendChild(form);
                    form.submit();
                    document.body.removeChild(form);
                }
            });
        })
        .catch(e => { Swal.fire('Error', 'Fallo al analizar el stock', 'error'); });
    }
    // ---------------------------------------------
    
    function confirmarBorrar(id, n) { Swal.fire({ title: '¿Eliminar a ' + n + '?', text: 'Solo si no tiene productos.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', confirmButtonColor: '#d33' }).then((r) => { if (r.isConfirmed) window.location.href = 'proveedores.php?borrar=' + id; }); }
    function filtrarTabla() { const f = document.getElementById('buscador').value.toLowerCase(); document.querySelectorAll('#tablaProveedores tbody tr').forEach(r => { r.style.display = r.innerText.toLowerCase().includes(f) ? '' : 'none'; }); }

    if(new URLSearchParams(window.location.search).get('msg')) {
        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Operación Exitosa', showConfirmButton: false, timer: 2000 });
    }
</script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<style>
    .select2-container .select2-selection--single { 
        height: 38px !important; border: 1px solid #dee2e6 !important; 
        border-radius: 0.375rem 0 0 0.375rem !important; 
        display: flex; align-items: center; background-color: #f8f9fa; font-size: 13px !important; 
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px !important; }
    .select2-results__option { font-size: 12px !important; }
</style>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
    $(document).ready(function() {
        function formatoBanderaLista(pais) {
            if (!pais.id) return pais.text;
            let iso = pais.element.getAttribute('data-iso');
            let nombre = pais.element.getAttribute('data-name');
            return $('<span><img src="https://flagcdn.com/w20/' + iso + '.png" style="width:16px; margin-right:4px; margin-bottom:1px;" />' + nombre + '</span>');
        }
        function formatoBanderaCerrado(pais) {
            if (!pais.id) return pais.text;
            let iso = pais.element.getAttribute('data-iso');
            return $('<span><img src="https://flagcdn.com/w20/' + iso + '.png" style="width:16px; margin-right:2px; margin-bottom:1px;" />' + pais.text + '</span>');
        }

        $('#cod_pais').select2({
            templateResult: formatoBanderaLista,
            templateSelection: formatoBanderaCerrado,
            minimumResultsForSearch: Infinity,
            dropdownParent: $('#modalProveedor')
        });
    });
</script>
<?php include 'includes/layout_footer.php'; ?>