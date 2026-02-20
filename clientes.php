<?php
// clientes.php - VERSIÓN PREMIUM FINAL (SINCRO TOTAL + OBJETOS + CAJA NEGRA)
session_start();
error_reporting(E_ALL); 
ini_set('display_errors', 1);

// 1. CONEXIÓN
$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// 2. LÓGICA DE ACCIONES (Antes de cualquier salida HTML)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['reset_pass'])) {
    $nombre    = trim($_POST['nombre']);
    $dni       = trim($_POST['dni']);
    $telefono  = trim($_POST['telefono']);
    $fecha_nac = !empty($_POST['fecha_nacimiento']) ? $_POST['fecha_nacimiento'] : null;
    $limite    = floatval($_POST['limite'] ?? 0);
    $user_form = trim($_POST['usuario_form'] ?? '');
    $id_edit   = $_POST['id_edit'] ?? '';
    $email     = trim($_POST['email'] ?? '');

    if (!empty($nombre) && !empty($dni) && !empty($email)) {
        try {
            // Manejo de valores opcionales para evitar errores de tipo
            $tel_val = !empty($telefono) ? $telefono : null;
            $dir_val = !empty($direccion) ? $direccion : null;
            $nac_val = !empty($fecha_nac) ? $fecha_nac : null;

            if ($id_edit) {
                $sql = "UPDATE clientes SET nombre=?, telefono=?, email=?, direccion=?, dni=?, dni_cuit=?, limite_credito=?, fecha_nacimiento=?, usuario=? WHERE id=?";
                $conexion->prepare($sql)->execute([$nombre, $tel_val, $email, $dir_val, $dni, $dni, $limite, $nac_val, $user_form, $id_edit]);
            } else {
                $sql = "INSERT INTO clientes (nombre, dni, dni_cuit, telefono, email, fecha_nacimiento, limite_credito, usuario, direccion) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $conexion->prepare($sql)->execute([$nombre, $dni, $dni, $telefono, $email, $fecha_nac, $limite, $user_form, $direccion]);
            }
            header("Location: clientes.php?msg=ok"); exit;
        } catch (Exception $e) { die("Error Crítico DB: " . $e->getMessage()); }
    }
}

// 3. BORRADO
if (isset($_GET['borrar'])) {
    if (!isset($_GET['token']) || $_GET['token'] !== $_SESSION['csrf_token']) { die("Error de seguridad."); }
    $id_b = intval($_GET['borrar']);
    try {
        $conexion->prepare("UPDATE ventas SET id_cliente = 1 WHERE id_cliente = ?")->execute([$id_b]);
        $conexion->prepare("DELETE FROM movimientos_cc WHERE id_cliente = ?")->execute([$id_b]);
        $conexion->prepare("DELETE FROM clientes WHERE id = ?")->execute([$id_b]);
        header("Location: clientes.php?msg=eliminado"); exit;
    } catch (Exception $e) { header("Location: clientes.php?error=db"); exit; }
}

// 4. CONSULTA DE DATOS (OBJETOS stdClass)
$sql = "SELECT c.*, 
        (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'debe') - 
        (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'haber') as saldo_calculado,
        (SELECT MAX(fecha) FROM ventas WHERE id_cliente = c.id) as ultima_venta_fecha
        FROM clientes c ORDER BY c.nombre ASC";

$clientes = $conexion->query($sql)->fetchAll();

$clientes_json = [];
$totalDeudaCalle = 0; $cntDeudores = 0; $cntAlDia = 0;
$totalClientes = count($clientes);

foreach($clientes as $c) {
    $saldo = floatval($c->saldo_calculado);
    if($saldo > 0.1) { $totalDeudaCalle += $saldo; $cntDeudores++; } else { $cntAlDia++; }
    
    $clientes_json[$c->id] = [
        'id' => $c->id, 'nombre' => htmlspecialchars($c->nombre), 'dni' => $c->dni ?? '',
        'email' => $c->email ?? '', 'fecha_nacimiento' => $c->fecha_nacimiento, 'telefono' => $c->telefono ?? '',
        'limite' => $c->limite_credito, 'deuda' => $saldo, 'puntos' => $c->puntos_acumulados,
        'usuario' => $c->usuario ?? '', 'direccion' => $c->direccion ?? '',
        'ultima_venta' => $c->ultima_venta_fecha ? date('d/m/Y', strtotime($c->ultima_venta_fecha)) : 'Nunca'
    ];
}

// COLOR SISTEMA
$color_sistema = '#102A57';
try {
    $conf = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1")->fetch();
    if ($conf) $color_sistema = $conf->color_barra_nav;
} catch (Exception $e) { }
?>

<?php include 'includes/layout_header.php'; ?></div>

<div class="header-blue" style="background-color: <?php echo $color_sistema; ?> !important;">
    <i class="bi bi-people-fill bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Cartera de Clientes</h2>
                <p class="opacity-75 mb-0 text-white small">Gestión de cuentas y fidelización</p>
            </div>
            <button type="button" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm" onclick="abrirModalCrear()">
                <i class="bi bi-person-plus-fill me-2"></i> NUEVO CLIENTE
            </button>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div><div class="widget-label">Total Clientes</div><div class="widget-value text-white"><?php echo $totalClientes; ?></div></div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-people"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div><div class="widget-label">Deuda en Calle</div><div class="widget-value text-white">$<?php echo number_format($totalDeudaCalle, 0, ',', '.'); ?></div></div>
                    <div class="icon-box bg-danger bg-opacity-20 text-white"><i class="bi bi-graph-down-arrow"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div><div class="widget-label">Clientes al Día</div><div class="widget-value text-white"><?php echo $cntAlDia; ?></div></div>
                    <div class="icon-box bg-success bg-opacity-20 text-white"><i class="bi bi-shield-check"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <div class="card card-custom">
        <div class="card-header bg-white py-3 border-0">
            <input type="text" id="buscador" class="form-control bg-light" placeholder="Buscar por nombre o DNI..." onkeyup="filtrarClientes()">
        </div>
        <div class="table-responsive px-2">
            <table class="table table-custom align-middle">
                <thead>
                    <tr>
                        <th class="ps-4">Cliente</th>
                        <th>DNI / Usuario</th>
                        <th>Puntos</th>
                        <th>Estado de Cuenta</th>
                        <th>Última Compra</th>
                        <th class="text-end pe-4">Acciones</th>
                    </tr>
                </thead>
                <tbody class="bg-white">
            <?php foreach($clientes as $c): 
                $deuda = floatval($c->saldo_calculado);
                $limite = floatval($c->limite_credito);
            ?>
            <tr class="cliente-row">
                <td class="ps-4">
                    <div class="d-flex align-items-center">
                        <div class="avatar-circle me-3 bg-primary bg-opacity-10 text-primary"><?php echo strtoupper(substr($c->nombre, 0, 2)); ?></div>
                        <div><div class="fw-bold text-dark"><?php echo $c->nombre; ?></div><small class="text-muted">ID #<?php echo $c->id; ?></small></div>
                    </div>
                </td>
                <td>
                    <div class="small fw-bold text-dark"><?php echo $c->dni ?: '--'; ?></div>
                    <span class="badge bg-light text-primary border">@<?php echo $c->usuario ?: 'sin_usuario'; ?></span>
                </td>
                <td><span class="badge bg-warning bg-opacity-10 text-dark fw-bold"><i class="bi bi-star-fill text-warning me-1"></i> <?php echo number_format($c->puntos_acumulados, 0); ?></span></td>
                <td>
                    <div class="small">
                        <div class="<?php echo $deuda > 0 ? 'text-danger fw-bold' : 'text-success'; ?>">Debe: $<?php echo number_format($deuda, 0, ',', '.'); ?></div>
                        <div class="text-muted" style="font-size: 0.7rem;">Límite Fiado: $<?php echo number_format($limite, 0, ',', '.'); ?></div>
                    </div>
                </td>
                <td class="small text-muted"><?php echo $c->ultima_venta_fecha ? date('d/m/y', strtotime($c->ultima_venta_fecha)) : 'Nunca'; ?></td>
                <td class="text-end pe-4">
                    <div class="d-flex justify-content-end gap-2">
                        <button class="btn-action btn-eye" onclick="verResumen(<?php echo $c->id; ?>)" title="Ver Resumen"><i class="bi bi-eye"></i></button>
                        <a href="cuenta_cliente.php?id=<?php echo $c->id; ?>" class="btn-action btn-wallet" title="Cuenta Corriente">
                            <i class="bi bi-wallet2"></i>
                        </a>
                        <button class="btn-action btn-edit" onclick="editar(<?php echo $c->id; ?>)" title="Editar Cliente"><i class="bi bi-pencil"></i></button>
                        <button class="btn-action btn-del" onclick="borrarCliente(<?php echo $c->id; ?>)" title="Eliminar"><i class="bi bi-trash"></i></button>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="modalGestionCliente" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg">
    <div class="modal-header bg-dark text-white"><h5 class="modal-title fw-bold" id="titulo-modal">Nuevo Cliente</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body p-4"><form method="POST" id="formCliente">
        <input type="hidden" name="id_edit" id="id_edit">
        <div class="mb-3"><label class="form-label small fw-bold">Nombre Completo <span class="text-danger">*</span></label><input type="text" name="nombre" id="nombre" class="form-control fw-bold"></div>
        <div class="row g-2 mb-3">
            <div class="col-6"><label class="form-label small fw-bold">DNI <span class="text-danger">*</span></label><input type="text" name="dni" id="dni" class="form-control"></div>
            <div class="col-6"><label class="form-label small fw-bold">Usuario (Sugerido)</label><input type="text" name="usuario_form" id="usuario_form" class="form-control"></div>
        </div>
        <div class="row g-2 mb-3">
            <div class="col-6"><label class="form-label small fw-bold">Teléfono</label><input type="text" name="telefono" id="telefono" class="form-control"></div>
            <div class="col-6"><label class="form-label small fw-bold">Fecha Nac.</label><input type="date" name="fecha_nacimiento" id="fecha_nacimiento" class="form-control"></div>
        </div>
        <div class="mb-3"><label class="form-label small fw-bold">Email</label><input type="email" name="email" id="email" class="form-control"></div>
        <div class="mb-3"><label class="form-label small fw-bold">Dirección</label><input type="text" name="direccion" id="direccion" class="form-control"></div>
        <div class="mb-4 bg-light p-3 rounded border"><label class="form-label small fw-bold text-danger">Límite de Fiado ($)</label><input type="number" name="limite" id="limite" class="form-control fw-bold text-danger" value="0"></div>
        <div class="d-grid"><button type="submit" class="btn btn-primary btn-lg fw-bold shadow">GUARDAR CLIENTE</button></div>
    </form></div>
</div></div></div>

<div class="modal fade" id="modalResumen" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg">
    <div class="modal-header bg-primary text-white border-0"><h5 class="modal-title fw-bold">Resumen de Cuenta</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body text-center p-4">
        <div class="bg-light rounded-circle d-inline-flex align-items-center justify-content-center mb-3" style="width:80px;height:80px;font-size:2rem;font-weight:bold;color:#102A57;" id="modal-avatar"></div>
        <h4 class="fw-bold mb-0" id="modal-nombre"></h4>
        <p class="text-muted" id="modal-dni"></p>
        <hr>
        <div class="row g-3">
            <div class="col-6"><div class="p-3 bg-light rounded"><div class="small text-muted">Deuda</div><div class="h5 fw-bold text-danger mb-0" id="modal-deuda"></div></div></div>
            <div class="col-6"><div class="p-3 bg-light rounded"><div class="small text-muted">Puntos</div><div class="h5 fw-bold text-warning mb-0" id="modal-puntos"></div></div></div>
        </div>
        <div class="d-grid mt-4"><a href="#" id="btn-ir-cuenta" class="btn btn-primary fw-bold shadow">VER CUENTA CORRIENTE</a></div>
    </div>
</div></div></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    // Usamos JSON_INVALID_UTF8_SUBSTITUTE para evitar que el script muera por caracteres raros
    const clientesDB = <?php echo json_encode($clientes_json, JSON_INVALID_UTF8_SUBSTITUTE); ?>;
    
    // Inicialización segura: Esperamos a que cargue el footer/bootstrap
    let modalG, modalR;
    document.addEventListener('DOMContentLoaded', function() {
        if(typeof bootstrap !== 'undefined') {
            modalG = new bootstrap.Modal(document.getElementById('modalGestionCliente'));
            modalR = new bootstrap.Modal(document.getElementById('modalResumen'));
        }
        // INTERCEPTOR DE VALIDACIÓN PREMIUM
    document.getElementById('formCliente').addEventListener('submit', function(e) {
        const nombre = document.getElementById('nombre').value.trim();
        const dni = document.getElementById('dni').value.trim();
        const email = document.getElementById('email').value.trim();
        let faltantes = [];

        if(!nombre) faltantes.push("Nombre Completo");
        if(!dni) faltantes.push("DNI");
        if(!email) faltantes.push("Email");

        if(faltantes.length > 0) {
            e.preventDefault(); // Detenemos el envío
            Swal.fire({
                icon: 'warning',
                title: 'Atención Deportiva',
                html: `Para entrar a la cancha debes completar:<br><br><b class="text-danger">${faltantes.join(', ')}</b>`,
                confirmButtonColor: '#102A57',
                confirmButtonText: 'ENTENDIDO'
            });
        }
    });
    });

    function abrirModalCrear() { 
        document.getElementById('formCliente').reset(); 
        document.getElementById('id_edit').value = ''; 
        document.getElementById('titulo-modal').innerText = "Nuevo Cliente"; 
        if(modalG) modalG.show(); 
    }

    function editar(id) { 
        const data = clientesDB[id];
        if(!data) return;
        document.getElementById('id_edit').value = data.id; 
        document.getElementById('nombre').value = data.nombre; 
        document.getElementById('dni').value = data.dni; 
        document.getElementById('telefono').value = data.telefono;
        document.getElementById('fecha_nacimiento').value = data.fecha_nacimiento;
        document.getElementById('email').value = data.email;
        document.getElementById('limite').value = data.limite;
        document.getElementById('direccion').value = data.direccion;
        document.getElementById('usuario_form').value = data.usuario;
        document.getElementById('titulo-modal').innerText = "Editar Cliente"; 
        if(modalG) modalG.show(); 
    }

    function verResumen(id) { 
        const data = clientesDB[id];
        if(!data) return;
        document.getElementById('modal-nombre').innerText = data.nombre; 
        document.getElementById('modal-dni').innerText = 'DNI: ' + data.dni; 
        document.getElementById('modal-avatar').innerText = data.nombre.substring(0,2).toUpperCase(); 
        document.getElementById('modal-deuda').innerText = '$' + data.deuda; 
        document.getElementById('modal-puntos').innerText = data.puntos; 
        document.getElementById('btn-ir-cuenta').href = 'cuenta_cliente.php?id=' + data.id; 
        if(modalR) modalR.show(); 
    }

    function filtrarClientes() { 
        let val = document.getElementById('buscador').value.toUpperCase(); 
        document.querySelectorAll('.cliente-row').forEach(row => {
            row.style.display = row.innerText.toUpperCase().includes(val) ? '' : 'none';
        });
    }

    function borrarCliente(id) { 
        if(typeof Swal !== 'undefined') {
            Swal.fire({ title: '¿Eliminar cliente?', icon: 'warning', showCancelButton: true, confirmButtonColor: '#d33' }).then((r) => { 
                if (r.isConfirmed) window.location.href = 'clientes.php?borrar=' + id + '&token=<?php echo $_SESSION['csrf_token']; ?>'; 
            }); 
        } else {
            if(confirm('¿Eliminar cliente?')) window.location.href = 'clientes.php?borrar=' + id + '&token=<?php echo $_SESSION['csrf_token']; ?>';
        }
    }

    document.getElementById('nombre').addEventListener('input', function(e) {
        if(document.getElementById('id_edit').value === '') {
            let parts = e.target.value.trim().toLowerCase().split(/\s+/);
            if(parts.length >= 2) {
                let suggested = (parts[0].charAt(0) + parts[parts.length - 1]).normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]/g, "");
                document.getElementById('usuario_form').value = suggested;
            }
        }
    });
</script>

<?php 
function enviarBienvenidaFutbolera($email, $nombre, $conexion) {
    try {
        require_once __DIR__ . '/libs/PHPMailer/src/Exception.php';
        require_once __DIR__ . '/libs/PHPMailer/src/PHPMailer.php';
        require_once __DIR__ . '/libs/PHPMailer/src/SMTP.php';
        $conf = $conexion->query("SELECT nombre_negocio FROM configuracion WHERE id=1")->fetch();
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP(); $mail->Host = 'smtp.hostinger.com'; $mail->SMTPAuth = true;
        $mail->Username = 'info@federicogonzalez.net'; $mail->Password = 'Fmg35911@';
        $mail->SMTPSecure = 'ssl'; $mail->Port = 465; $mail->CharSet = 'UTF-8';
        $mail->setFrom('info@federicogonzalez.net', $conf->nombre_negocio);
        $mail->addAddress($email, $nombre); $mail->isHTML(true);
        $mail->Subject = "¡Bienvenido!";
        $mail->Body = "Hola $nombre, ya sos parte del equipo.";
        $mail->send();
    } catch (Exception $e) {}
}
include 'includes/layout_footer.php'; ?>