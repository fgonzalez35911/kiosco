<?php
// clientes.php - VERSIÓN INTEGRAL RECUPERADA (Estructura espejo de canje_puntos.php)
session_start();
error_reporting(0); 

// 1. CONEXIÓN (Estructura idéntica a canje_puntos.php)
$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// 2. LÓGICA DE BORRADO (Seguridad recuperada - No borra si tiene deudas o ventas)
if (isset($_GET['borrar'])) {
    if (!isset($_GET['token']) || $_GET['token'] !== $_SESSION['csrf_token']) {
        die("Error de seguridad: Token inválido.");
    }
    $id_borrar = intval($_GET['borrar']);
    try {
        $checkCC = $conexion->prepare("SELECT COUNT(*) FROM movimientos_cc WHERE id_cliente = ?");
        $checkCC->execute([$id_borrar]);
        
        $checkVentas = $conexion->prepare("SELECT COUNT(*) FROM ventas WHERE id_cliente = ?");
        $checkVentas->execute([$id_borrar]);

        if ($checkCC->fetchColumn() > 0 || $checkVentas->fetchColumn() > 0) {
             header("Location: clientes.php?error=tiene_datos"); exit;
        }

        $conexion->prepare("DELETE FROM clientes WHERE id = ?")->execute([$id_borrar]);
        header("Location: clientes.php?msg=eliminado"); exit;

    } catch (Exception $e) { 
        header("Location: clientes.php?error=db"); exit; 
    }
}

// 3. LÓGICA DE RESET DE CLAVE (Clave pasa a ser el DNI)
if (isset($_POST['reset_pass'])) {
    $id_reset = $_POST['id_reset'];
    $dni_reset = $_POST['dni_reset'];
    $hash_nuevo = password_hash($dni_reset, PASSWORD_DEFAULT);
    try {
        $conexion->prepare("UPDATE clientes SET password = ? WHERE id = ?")->execute([$hash_nuevo, $id_reset]);
        header("Location: clientes.php?msg=pass_ok"); exit;
    } catch (Exception $e) {
        header("Location: clientes.php?error=db"); exit;
    }
}

// 4. LÓGICA DE CREACIÓN / EDICIÓN (Tu lógica original)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && !isset($_POST['reset_pass'])) {
    $nombre = trim($_POST['nombre']);
$dni = trim($_POST['dni']);
$telefono = trim($_POST['telefono']);
$fecha_nac = $_POST['fecha_nacimiento']; // Nuevo campo
$limite = $_POST['limite'];
$id_edit = $_POST['id_edit'] ?? '';

if (!empty($nombre)) {
    if ($id_edit) {
        $sql = "UPDATE clientes SET nombre=?, dni=?, telefono=?, fecha_nacimiento=?, limite_credito=? WHERE id=?";
        $conexion->prepare($sql)->execute([$nombre, $dni, $telefono, $fecha_nac, $limite, $id_edit]);
    } else {
        $sql = "INSERT INTO clientes (nombre, dni, telefono, fecha_nacimiento, limite_credito, fecha_registro) VALUES (?, ?, ?, ?, ?, NOW())";
        $conexion->prepare($sql)->execute([$nombre, $dni, $telefono, $fecha_nac, $limite]);
    }
        header("Location: clientes.php"); exit;
    }
}

// 5. CONSULTA DE DATOS (Tus subconsultas de deudas intactas)
$sql = "SELECT c.*, 
        (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'debe') - 
        (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = c.id AND tipo = 'haber') as saldo_calculado,
        (SELECT MAX(fecha) FROM ventas WHERE id_cliente = c.id) as ultima_venta_fecha
        FROM clientes c 
        ORDER BY c.nombre ASC";

$clientes = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$clientes_json = [];
$totalDeudaCalle = 0;
$cntDeudores = 0;
$cntAlDia = 0;
$totalClientes = count($clientes);

foreach($clientes as $c) {
    $hoy_mes_dia = date('m-d');
    $cumple_mes_dia = $c['fecha_nacimiento'] ? date('m-d', strtotime($c['fecha_nacimiento'])) : '';
    $es_cumple = ($hoy_mes_dia == $cumple_mes_dia) ? '1' : '0';
    if($c['saldo_calculado'] > 0.1) {
        $totalDeudaCalle += $c['saldo_calculado'];
        $cntDeudores++;
    } else { $cntAlDia++; }
    
    $clientes_json[$c['id']] = [
    'id' => $c['id'],
    'nombre' => htmlspecialchars($c['nombre']),
    'dni' => $c['dni'] ?? 'No registrado',
    'fecha_nacimiento' => $c['fecha_nacimiento'], // Agregá esta línea
        'telefono' => $c['telefono'] ?? 'No registrado',
        'limite' => $c['limite_credito'],
        'deuda' => $c['saldo_calculado'],
        'puntos' => $c['puntos_acumulados'],
        'usuario' => $c['usuario'] ?? 'sin_usuario',
        'ultima_venta' => $c['ultima_venta_fecha'] ? date('d/m/Y', strtotime($c['ultima_venta_fecha'])) : 'Nunca'
    ];
}
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
    <i class="bi bi-people-fill bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Cartera de Clientes</h2>
                <p class="opacity-75 mb-0 text-white small">Administración de cuentas corrientes y fidelización.</p>
            </div>
            <button type="button" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm" onclick="abrirModalCrear()">
                <i class="bi bi-person-plus-fill me-2"></i> NUEVO CLIENTE
            </button>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="header-widget" onclick="filtrarEstado('todos')" style="cursor:pointer;">
                    <div>
                        <div class="widget-label">Total Clientes</div>
                        <div class="widget-value text-white"><?php echo $totalClientes; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white">
                        <i class="bi bi-people"></i>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="header-widget" onclick="filtrarEstado('deuda')" style="cursor:pointer;">
                    <div>
                        <div class="widget-label">Deuda en Calle</div>
                        <div class="widget-value text-white">$<?php echo number_format($totalDeudaCalle, 0, ',', '.'); ?></div>
                    </div>
                    <div class="icon-box bg-danger bg-opacity-20 text-white">
                        <i class="bi bi-graph-down-arrow"></i>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="header-widget border-info" onclick="filtrarEstado('aldia')" style="cursor:pointer;">
                    <div>
                        <div class="widget-label">Clientes al Día</div>
                        <div class="widget-value text-white"><?php echo $cntAlDia; ?></div>
                    </div>
                    <div class="icon-box bg-success bg-opacity-20 text-white">
                        <i class="bi bi-shield-check"></i>
                    </div>
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
    <table class="table table-custom align-middle" id="tablaClientes">
        <thead>
            <tr>
                <th class="ps-4">Cliente</th>
                <th>DNI / Usuario</th>
                <th>Contacto</th>
                <th>Cumpleaños</th>
                <th>Puntos</th>
                <th>Crédito / Deuda</th>
                <th>Última Compra</th>
                <th class="text-end pe-4">Acciones</th>
            </tr>
        </thead>
        <tbody class="bg-white">
    <?php foreach($clientes as $c): 
        $deuda = floatval($c['saldo_calculado']);
        $limite = floatval($c['limite_credito']);
        $disponible = $limite - $deuda;
        $esDeudor = ($deuda > 0.1) ? '1' : '0';
        
        $hoy = date('m-d');
        $f_nac = $c['fecha_nacimiento'] ? date('m-d', strtotime($c['fecha_nacimiento'])) : '';
        $es_cumple_fila = ($hoy == $f_nac) ? '1' : '0';
    ?>
    <tr class="cliente-row" data-es-deudor="<?php echo $esDeudor; ?>" data-cumple="<?php echo $es_cumple_fila; ?>">
        <td data-label="Cliente" class="ps-4">
            <div class="d-flex align-items-center">
                <div class="avatar-circle me-3 bg-primary bg-opacity-10 text-primary">
                    <?php echo strtoupper(substr($c['nombre'], 0, 2)); ?>
                </div>
                <div>
                    <div class="fw-bold text-dark"><?php echo $c['nombre']; ?></div>
                    <small class="text-muted">ID #<?php echo $c['id']; ?></small>
                </div>
            </div>
        </td>
        
        <td data-label="Identidad">
            <div class="small fw-bold text-dark"><?php echo $c['dni'] ?: '--'; ?></div>
            <span class="badge bg-light text-primary border">@<?php echo $c['usuario'] ?: 'sin_usuario'; ?></span>
        </td>

        <td data-label="Contacto">
            <?php if($c['telefono']): ?>
                <a href="https://wa.me/<?php echo $c['telefono']; ?>" target="_blank" class="text-decoration-none text-success fw-bold small">
                    <i class="bi bi-whatsapp me-1"></i> <?php echo $c['telefono']; ?>
                </a>
            <?php else: ?>
                <span class="text-muted small">---</span>
            <?php endif; ?>
        </td>

        <td data-label="Cumpleaños">
            <div class="small <?php echo $es_cumple_fila ? 'fw-bold text-danger' : ''; ?>">
                <i class="bi <?php echo $es_cumple_fila ? 'bi-cake2-fill' : 'bi-calendar-event'; ?> me-1"></i>
                <?php echo $c['fecha_nacimiento'] ? date('d/m', strtotime($c['fecha_nacimiento'])) : '--/--'; ?>
            </div>
        </td>

        <td data-label="Puntos">
            <span class="badge bg-warning bg-opacity-10 text-dark fw-bold">
                <i class="bi bi-star-fill text-warning me-1"></i> <?php echo number_format($c['puntos_acumulados'], 0); ?>
            </span>
        </td>

        <td data-label="Crédito/Deuda">
            <div class="small">
                <div class="<?php echo $deuda > 0 ? 'text-danger fw-bold' : 'text-success'; ?>">
                    Debe: $<?php echo number_format($deuda, 0, ',', '.'); ?>
                </div>
                <div class="text-muted" style="font-size: 0.7rem;">
                    Disponible: $<?php echo number_format($disponible, 0, ',', '.'); ?>
                </div>
            </div>
        </td>

        <td data-label="Última Compra">
            <div class="small text-muted">
                <?php echo $c['ultima_venta_fecha'] ? date('d/m/y', strtotime($c['ultima_venta_fecha'])) : 'Sin compras'; ?>
            </div>
        </td>

        <td class="text-end pe-4">
            <div class="d-flex justify-content-end">
                <button class="btn-action btn-eye" onclick="verResumen(<?php echo $c['id']; ?>)" title="Resumen"><i class="bi bi-eye"></i></button>
                <a href="cuenta_cliente.php?id=<?php echo $c['id']; ?>" class="btn-action btn-wallet" title="Historial"><i class="bi bi-wallet2"></i></a>
                <button class="btn-action btn-edit" onclick="editar(<?php echo $c['id']; ?>)" title="Editar"><i class="bi bi-pencil"></i></button>
                <button class="btn-action btn-del" onclick="borrarCliente(<?php echo $c['id']; ?>)" title="Eliminar"><i class="bi bi-trash"></i></button>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
</tbody>
    </table>
</div>
    </div>
</div>

<div class="modal fade" id="modalGestionCliente" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0">
    <div class="modal-header bg-dark text-white"><h5 class="modal-title fw-bold" id="titulo-modal">Nuevo Cliente</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
    <div class="modal-body p-4"><form method="POST" id="formCliente"><input type="hidden" name="id_edit" id="id_edit">
    <div class="mb-3"><label class="form-label small fw-bold">Nombre Completo *</label><input type="text" name="nombre" id="nombre" class="form-control fw-bold" required></div>
    <div class="row g-2 mb-3"><div class="col-6"><label class="form-label small fw-bold">DNI</label><input type="text" name="dni" id="dni" class="form-control"></div>
    <div class="col-6"><label class="form-label small fw-bold">Teléfono</label><input type="text" name="telefono" id="telefono" class="form-control"></div></div>
    <div class="mb-3">
    <label class="form-label small fw-bold">Fecha de Nacimiento</label>
    <input type="date" name="fecha_nacimiento" id="fecha_nacimiento" class="form-control">
</div>
    <div class="mb-4 bg-light p-3 rounded border"><label class="form-label small fw-bold text-danger">Límite de Fiado ($)</label><input type="number" name="limite" id="limite" class="form-control fw-bold text-danger" value="0"></div>
    <div class="d-grid"><button type="submit" class="btn btn-primary btn-lg fw-bold" id="btn-guardar">GUARDAR CLIENTE</button></div></form>
</div></div></div></div>

<div class="modal fade" id="modalResumen" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><div class="modal-content border-0 shadow-lg"><div class="modal-header modal-header-custom border-0 pb-4"><h5 class="modal-title fw-bold">Resumen de Cuenta</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body pt-0 text-center" style="margin-top:-30px"><div class="bg-white rounded-circle shadow d-inline-flex align-items-center justify-content-center" style="width:70px;height:70px;font-size:2rem;font-weight:bold;color:#102A57;" id="modal-avatar"></div><h4 class="mt-2 fw-bold" id="modal-nombre"></h4><span class="badge bg-light text-dark border mb-3" id="modal-dni"></span><div class="row g-2"><div class="col-6"><div class="stat-box border-danger border-start border-4 shadow-sm"><div id="modal-deuda" style="font-size:1.5rem;font-weight:700;color:#dc3545"></div><div class="small text-muted">Deuda Actual</div></div></div><div class="col-6"><div class="stat-box border-success border-start border-4 shadow-sm"><div id="modal-disponible" style="font-size:1.5rem;font-weight:700;color:#198754"></div><div class="small text-muted">Disponible</div></div></div></div><div class="d-grid mt-4"><a href="#" id="btn-ir-cuenta" class="btn btn-primary btn-lg fw-bold shadow">VER HISTORIAL</a></div></div></div></div></div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    const clientesDB = <?php echo json_encode($clientes_json); ?>;
    function abrirModalCrear() { document.getElementById('formCliente').reset(); document.getElementById('id_edit').value = ''; document.getElementById('titulo-modal').innerText = "Nuevo Cliente"; $('#modalGestionCliente').modal('show'); }
    function editar(id) { const data = clientesDB[id]; document.getElementById('id_edit').value = data.id; document.getElementById('nombre').value = data.nombre; document.getElementById('dni').value = (data.dni === 'No registrado') ? '' : data.dni; 
    document.getElementById('telefono').value = (data.telefono === 'No registrado') ? '' : data.telefono;
    document.getElementById('fecha_nacimiento').value = data.fecha_nacimiento;
    document.getElementById('limite').value = data.limite;



document.getElementById('titulo-modal').innerText = "Editar Cliente"; $('#modalGestionCliente').modal('show'); }
    function verResumen(id) { const data = clientesDB[id]; document.getElementById('modal-nombre').innerText = data.nombre; document.getElementById('modal-dni').innerText = 'DNI: ' + data.dni; document.getElementById('modal-avatar').innerText = data.nombre.substring(0,2).toUpperCase(); document.getElementById('modal-deuda').innerText = '$' + data.deuda; document.getElementById('modal-disponible').innerText = (data.limite > 0) ? '$' + (data.limite - data.deuda) : 'Ilimitado'; document.getElementById('btn-ir-cuenta').href = 'cuenta_cliente.php?id=' + data.id; $('#modalResumen').modal('show'); }
    function filtrarEstado(tipo) { 
    $('.cliente-row').each(function() { 
        let d = $(this).data('es-deudor') == 1; 
        let c = $(this).data('cumple') == 1;
        if(tipo === 'deuda') $(this).toggle(d); 
        else if(tipo === 'aldia') $(this).toggle(!d); 
        else if(tipo === 'cumple') $(this).toggle(c);
        else $(this).show(); 
    }); 
}
    function filtrarClientes() { let val = $('#buscador').val().toUpperCase(); $('.cliente-row').each(function() { $(this).toggle($(this).text().toUpperCase().indexOf(val) > -1); }); }
    function borrarCliente(id) { Swal.fire({ title: '¿Eliminar?', text: "No se recuperará.", icon: 'warning', showCancelButton: true }).then((r) => { if (r.isConfirmed) window.location.href = 'clientes.php?borrar=' + id + '&token=<?php echo $_SESSION['csrf_token']; ?>'; }); }

    
    // Autofiltrado desde Dashboard
    $(document).ready(function() {
        const urlParams = new URLSearchParams(window.location.search);
        if(urlParams.get('filtro') === 'cumple') {
            // Ejecutamos el filtro automáticamente
            filtrarEstado('cumple');
            Swal.fire({ 
                icon: 'info', 
                title: 'Sección Cumpleaños', 
                text: 'Mostrando los agasajados del día.', 
                toast: true, 
                position: 'top-end', 
                timer: 3000 
            });
        }
    });
</script>

<?php include 'includes/layout_footer.php'; ?>