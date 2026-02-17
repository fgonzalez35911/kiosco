<?php
session_start();
require_once 'includes/db.php';

// Validar ID
if (!isset($_GET['id'])) { header("Location: sorteos.php"); exit; }
$id = $_GET['id'];

// Obtener datos del sorteo
$stmt = $conexion->prepare("SELECT * FROM sorteos WHERE id = ?");
$stmt->execute([$id]);
$sorteo = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$sorteo) { header("Location: sorteos.php"); exit; }

// Obtener premios y tickets
$premios = $conexion->query("SELECT sp.*, p.descripcion as prod_nombre FROM sorteo_premios sp LEFT JOIN productos p ON sp.id_producto = p.id WHERE id_sorteo = $id ORDER BY posicion ASC")->fetchAll(PDO::FETCH_ASSOC);
$tickets = $conexion->query("SELECT st.*, c.nombre FROM sorteo_tickets st JOIN clientes c ON st.id_cliente = c.id WHERE id_sorteo = $id ORDER BY numero_ticket ASC")->fetchAll(PDO::FETCH_ASSOC);
$clientes = $conexion->query("SELECT id, nombre FROM clientes ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

// Productos para el select (usando la misma lógica de costos puente)
$sqlProds = "SELECT p.id, p.descripcion, p.tipo, p.precio_costo, (SELECT COALESCE(SUM(ph.precio_costo * ci.cantidad),0) FROM combo_items ci JOIN combos c ON c.id=ci.id_combo JOIN productos ph ON ci.id_producto=ph.id WHERE c.codigo_barras=p.codigo_barras) as costo_combo FROM productos p WHERE p.activo=1 ORDER BY p.descripcion ASC";
$productos = $conexion->query($sqlProds)->fetchAll(PDO::FETCH_ASSOC);

$cantidad_vendidos = count($tickets);
$numeros_ocupados = array_column($tickets, 'numero_ticket');

// --- LÓGICA DE EDICIÓN (PERMITIDA SOLO SI NO HAY VENTAS) ---
$es_editable = ($cantidad_vendidos == 0 && $sorteo['estado'] == 'activo');

// --- ACCIONES ---

// 1. AGREGAR PREMIO
if (isset($_POST['add_premio'])) {
    if (!$es_editable) { die("No se puede editar, ya hay ventas o finalizó."); }
    $pos = $_POST['posicion'];
    $tipo = $_POST['tipo'];
    $id_prod = ($tipo == 'interno') ? $_POST['id_producto'] : NULL;
    $desc_ext = ($tipo == 'externo') ? $_POST['descripcion_externa'] : NULL;
    
    $conexion->prepare("INSERT INTO sorteo_premios (id_sorteo, posicion, tipo, id_producto, descripcion_externa) VALUES (?,?,?,?,?)")
             ->execute([$id, $pos, $tipo, $id_prod, $desc_ext]);
    header("Location: detalle_sorteo.php?id=$id"); exit;
}

// 2. ELIMINAR PREMIO
if (isset($_GET['del_premio'])) {
    if (!$es_editable) { die("No se puede editar."); }
    $idPremio = $_GET['del_premio'];
    $conexion->prepare("DELETE FROM sorteo_premios WHERE id = ? AND id_sorteo = ?")->execute([$idPremio, $id]);
    header("Location: detalle_sorteo.php?id=$id"); exit;
}

// 3. VENDER TICKET
if (isset($_POST['vender_ticket'])) {
    if ($sorteo['estado'] !== 'activo') { die("El sorteo no está activo."); }
    
    $idCliente = $_POST['id_cliente'];
    $numeroElegido = $_POST['numero_elegido'];
    $precioTicket = $sorteo['precio_ticket'];
    $idUsuario = $_SESSION['usuario_id'];
    
    if(empty($numeroElegido)) { echo "<script>alert('¡Selecciona un número!'); window.location.href='detalle_sorteo.php?id=$id';</script>"; exit; }

    $chk = $conexion->query("SELECT id FROM sorteo_tickets WHERE id_sorteo = $id AND numero_ticket = $numeroElegido")->fetch();
    if($chk) { echo "<script>alert('¡Número ocupado!'); window.location.href='detalle_sorteo.php?id=$id';</script>"; exit; }
    
    $caja = $conexion->query("SELECT id FROM cajas_sesion WHERE id_usuario = $idUsuario AND estado = 'abierta'")->fetch(PDO::FETCH_ASSOC);
    if (!$caja) { echo "<script>alert('¡Abrí la caja primero!'); window.location.href='detalle_sorteo.php?id=$id';</script>"; exit; }
    $idCaja = $caja['id'];

    try {
        $conexion->beginTransaction();
        $conexion->prepare("INSERT INTO sorteo_tickets (id_sorteo, id_cliente, numero_ticket) VALUES (?,?,?)")->execute([$id, $idCliente, $numeroElegido]);
        $codRef = "RIFA-{$id}-NUM-{$numeroElegido}";
        $conexion->prepare("INSERT INTO ventas (codigo_ticket, id_caja_sesion, id_usuario, id_cliente, total, metodo_pago, estado, origen) VALUES (?, ?, ?, ?, ?, 'Efectivo', 'completada', 'local')")->execute([$codRef, $idCaja, $idUsuario, $idCliente, $precioTicket]);
        $conexion->query("UPDATE cajas_sesion SET total_ventas = total_ventas + $precioTicket, monto_final = IFNULL(monto_final, 0) + $precioTicket WHERE id = $idCaja");
        
        $conexion->commit();
        header("Location: detalle_sorteo.php?id=$id&msg=ticket_ok"); exit;
    } catch (Exception $e) { $conexion->rollBack(); die($e->getMessage()); }
}

// 4. EDITAR DATOS PRINCIPALES
if (isset($_POST['editar_sorteo'])) {
    if (!$es_editable) die("No editable.");
    $titulo = $_POST['titulo'];
    $fecha = $_POST['fecha'];
    $precio = $_POST['precio']; 
    $cantidad = $_POST['cantidad'];
    $conexion->prepare("UPDATE sorteos SET titulo=?, fecha_sorteo=?, precio_ticket=?, cantidad_tickets=? WHERE id=?")->execute([$titulo, $fecha, $precio, $cantidad, $id]);
    header("Location: detalle_sorteo.php?id=$id&msg=editado"); exit;
}

// 5. SORTEAR (USANDO LA LÓGICA DE PUENTE DE COSTOS)
if (isset($_POST['ejecutar_sorteo'])) {
    if ($sorteo['estado'] != 'activo') die(json_encode(['error' => 'No está activo']));
    $participantes = $conexion->query("SELECT id, id_cliente, numero_ticket FROM sorteo_tickets WHERE id_sorteo = $id")->fetchAll(PDO::FETCH_ASSOC);
    if (count($participantes) == 0) die(json_encode(['error' => 'No hay tickets vendidos.']));
    
    shuffle($participantes); 
    $ganadores = []; $uid = $_SESSION['usuario_id'];
    
    foreach ($premios as $index => $premio) {
        if (!isset($participantes[$index])) break; 
        $ganador = $participantes[$index];
        $ganadores[] = ['posicion' => $premio['posicion'], 'premio' => ($premio['tipo']=='interno' ? $premio['prod_nombre'] : $premio['descripcion_externa']), 'cliente' => $conexion->query("SELECT nombre FROM clientes WHERE id = {$ganador['id_cliente']}")->fetchColumn(), 'ticket' => $ganador['numero_ticket']];
        
        // GASTO Y STOCK
        if ($premio['tipo'] == 'interno' && $premio['id_producto']) {
            $conexion->query("UPDATE productos SET stock_actual = stock_actual - 1 WHERE id = {$premio['id_producto']}");
            
            // Calculo de costo preciso
            $prodInfo = $conexion->query("SELECT tipo, precio_costo, codigo_barras FROM productos WHERE id = {$premio['id_producto']}")->fetch(PDO::FETCH_ASSOC);
            $costo = $prodInfo['precio_costo'];
            
            if ($prodInfo['tipo'] == 'combo') {
                $sqlCostoCombo = "SELECT SUM(p.precio_costo * ci.cantidad) FROM combo_items ci JOIN combos c ON c.id = ci.id_combo JOIN productos p ON ci.id_producto = p.id WHERE c.codigo_barras = '{$prodInfo['codigo_barras']}'";
                $costoCalc = $conexion->query($sqlCostoCombo)->fetchColumn();
                if ($costoCalc > 0) $costo = $costoCalc;
            }

            $cajaActiva = $conexion->query("SELECT id FROM cajas_sesion WHERE id_usuario = $uid AND estado = 'abierta'")->fetchColumn() ?: 1;
            $conexion->prepare("INSERT INTO gastos (descripcion, monto, categoria, id_usuario, fecha, id_caja_sesion) VALUES (?, ?, 'Sorteo', ?, NOW(), ?)")->execute(["Premio Sorteo #$id: " . $premio['prod_nombre'], $costo, $uid, $cajaActiva]);
        }
    }
    
    $conexion->prepare("UPDATE sorteos SET estado = 'finalizado', ganadores_json = ? WHERE id = ?")->execute([json_encode($ganadores), $id]);
    echo json_encode(['status' => 'ok', 'ganadores' => $ganadores]); exit;
}
?>

<?php include 'includes/layout_header.php'; ?>

<style>
    .roulette-container { border: 4px solid #102A57; border-radius: 15px; overflow: hidden; position: relative; background: #222; height: 100px; display: flex; align-items: center; justify-content: center; }
    .roulette-window { font-size: 3rem; font-weight: bold; color: #fff; font-family: 'Courier New', monospace; }
    .grid-numeros { display: grid; grid-template-columns: repeat(auto-fill, minmax(45px, 1fr)); gap: 8px; max-height: 350px; overflow-y: auto; padding: 15px; border: 1px solid #ddd; border-radius: 10px; background: #fff; }
    .num-box { aspect-ratio: 1; display: flex; align-items: center; justify-content: center; border-radius: 8px; font-weight: bold; cursor: pointer; border: 1px solid #dee2e6; }
    .num-libre { background: #f8f9fa; } .num-ocupado { background: #dc3545; color: white; cursor: not-allowed; } .num-seleccionado { background: #198754; color: white; transform: scale(1.1); }
</style>

<div class="container py-4">
    <div class="d-flex justify-content-between align-items-center mb-4 flex-wrap gap-2">
        <div>
            <a href="sorteos.php" class="text-decoration-none text-muted mb-2 d-block"><i class="bi bi-arrow-left"></i> Volver</a>
            <h2 class="fw-bold text-primary mb-0">
                <?php echo htmlspecialchars($sorteo['titulo']); ?>
                <span class="badge <?php echo $sorteo['estado']=='activo'?'bg-success':'bg-secondary'; ?> fs-6 align-middle ms-2"><?php echo strtoupper($sorteo['estado']); ?></span>
            </h2>
        </div>
        
        <div class="d-flex gap-2">
            <?php if($es_editable): ?>
                <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalEditar"><i class="bi bi-pencil"></i> Editar Datos</button>
            <?php endif; ?>

            <?php if($sorteo['estado'] == 'activo'): ?>
                <button class="btn btn-warning fw-bold shadow" onclick="iniciarSorteoVisual()"><i class="bi bi-trophy-fill me-2"></i> ¡SORTEAR!</button>
            <?php endif; ?>
        </div>
    </div>

    <div id="zonaSorteo" class="mb-5 d-none">
        <div class="card bg-dark text-white text-center p-4 rounded-4 shadow-lg">
            <h3 class="mb-3 text-warning">¡BUSCANDO GANADOR!</h3>
            <div class="roulette-container mb-3"><div class="roulette-window" id="rouletteDisplay">000</div></div>
            <h4 id="premioActualDisplay" class="text-info"></h4>
        </div>
    </div>

    <?php if($sorteo['estado'] == 'finalizado' && $sorteo['ganadores_json']): 
        $ganadoresData = json_decode($sorteo['ganadores_json'], true);
    ?>
    <div class="card border-warning mb-4 shadow-sm bg-light">
        <div class="card-header bg-warning text-dark fw-bold text-center"><i class="bi bi-star-fill me-2"></i> GANADORES OFICIALES</div>
        <div class="card-body">
            <div class="row text-center g-3">
                <?php foreach($ganadoresData as $g): ?>
                <div class="col-md-4">
                    <div class="card h-100 p-3 border-warning">
                        <div class="display-4">🥇</div>
                        <h5 class="fw-bold mt-2">Puesto #<?php echo $g['posicion']; ?></h5>
                        <h4 class="text-primary fw-bold text-uppercase"><?php echo $g['cliente']; ?></h4>
                        <p class="text-muted mb-0">Ticket #<?php echo str_pad($g['ticket'], 3, '0', STR_PAD_LEFT); ?></p>
                        <hr>
                        <small class="text-success fw-bold"><?php echo $g['premio']; ?></small>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-md-4">
            <div class="card shadow-sm mb-4 border-0">
                <div class="card-header bg-white fw-bold py-3">1. Premios</div>
                <div class="card-body">
                    <ul class="list-group list-group-flush mb-3">
                        <?php foreach($premios as $p): ?>
                        <li class="list-group-item d-flex justify-content-between align-items-center">
                            <div><span class="badge bg-primary rounded-circle me-2"><?php echo $p['posicion']; ?></span><?php echo $p['tipo']=='interno' ? $p['prod_nombre'] : $p['descripcion_externa']; ?></div>
                            <?php if($es_editable): ?>
                                <a href="detalle_sorteo.php?id=<?php echo $id; ?>&del_premio=<?php echo $p['id']; ?>" class="text-danger small" onclick="return confirm('¿Borrar?')"><i class="bi bi-trash"></i></a>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if($es_editable): ?>
                    <form method="POST" class="bg-light p-3 rounded">
                        <h6 class="fw-bold small text-muted">AGREGAR PREMIO</h6>
                        <div class="row g-2">
                            <div class="col-4"><input type="number" name="posicion" class="form-control form-control-sm" placeholder="Puesto" required></div>
                            <div class="col-8"><select name="tipo" class="form-select form-select-sm" onchange="togglePremio(this.value)"><option value="interno">Producto / Combo</option><option value="externo">Externo (Texto)</option></select></div>
                        </div>
                        <div class="mt-2" id="inputInterno">
                            <select name="id_producto" class="form-select form-select-sm">
                                <?php foreach($productos as $prod): 
                                    $costoShow = ($prod['costo_combo'] > 0) ? $prod['costo_combo'] : $prod['precio_costo'];
                                ?>
                                    <option value="<?php echo $prod['id']; ?>"><?php echo $prod['descripcion']; ?> (Costo: $<?php echo number_format($costoShow,0); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mt-2 d-none" id="inputExterno"><input type="text" name="descripcion_externa" class="form-control form-control-sm" placeholder="Ej: Microondas..."></div>
                        <button type="submit" name="add_premio" class="btn btn-primary btn-sm w-100 mt-2 fw-bold">Guardar Premio</button>
                    </form>
                    <?php else: ?>
                        <div class="alert alert-secondary small text-center"><i class="bi bi-lock-fill"></i> Premios bloqueados (Hay ventas)</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white fw-bold py-3 d-flex justify-content-between align-items-center">
                    <span>2. Venta de Tickets (<?php echo $cantidad_vendidos; ?>/<?php echo $sorteo['cantidad_tickets']; ?>)</span>
                    <span class="badge bg-success text-white p-2">$<?php echo number_format($cantidad_vendidos * $sorteo['precio_ticket'], 2); ?> Recaudado</span>
                </div>
                <div class="card-body">
                    <?php if($sorteo['estado'] == 'activo'): ?>
                    <div class="row">
                        <div class="col-md-5">
                            <form method="POST" id="formVenta" class="bg-light p-3 rounded">
                                <label class="small fw-bold">1. Elegir Cliente</label>
                                <div class="input-group mb-3">
                                    <select name="id_cliente" id="select_clientes" class="form-select">
                                        <?php foreach($clientes as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo $c['nombre']; ?></option><?php endforeach; ?>
                                    </select>
                                    <button class="btn btn-success" type="button" data-bs-toggle="modal" data-bs-target="#modalClienteRapido"><i class="bi bi-person-plus-fill"></i></button>
                                </div>
                                <label class="small fw-bold">2. Número</label>
                                <input type="text" name="numero_elegido" id="inputNumeroElegido" class="form-control mb-3 text-center fw-bold fs-4" readonly placeholder="Click en grilla" required>
                                <button type="submit" name="vender_ticket" class="btn btn-success w-100 fw-bold"><i class="bi bi-cash"></i> Cobrar Ticket</button>
                            </form>
                        </div>
                        <div class="col-md-7">
                            <div class="grid-numeros">
                                <?php for($i=1; $i<=$sorteo['cantidad_tickets']; $i++): 
                                    $ocupado = in_array($i, $numeros_ocupados);
                                    $claseNum = $ocupado ? 'num-ocupado' : 'num-libre';
                                ?>
                                <div class="num-box <?php echo $claseNum; ?>" onclick="<?php echo $ocupado ? '' : "seleccionarNumero($i, this)"; ?>">
                                    <?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?>
                                </div>
                                <?php endfor; ?>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                        <div class="alert alert-dark text-center">Sorteo Finalizado</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="modalEditar" tabindex="-1">
    <div class="modal-dialog">
        <form method="POST" class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Editar Sorteo</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label>Título</label><input type="text" name="titulo" class="form-control" value="<?php echo $sorteo['titulo']; ?>" required></div>
                <div class="mb-3"><label>Fecha Sorteo</label><input type="date" name="fecha" class="form-control" value="<?php echo date('Y-m-d', strtotime($sorteo['fecha_sorteo'])); ?>" required></div>
                <div class="row">
                    <div class="col-6 mb-3"><label>Precio Ticket</label><input type="number" name="precio" class="form-control" step="0.01" value="<?php echo $sorteo['precio_ticket']; ?>" required></div>
                    <div class="col-6 mb-3"><label>Cantidad Total</label><input type="number" name="cantidad" class="form-control" value="<?php echo $sorteo['cantidad_tickets']; ?>" required></div>
                </div>
            </div>
            <div class="modal-footer"><button type="submit" name="editar_sorteo" class="btn btn-primary">Guardar Cambios</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="modalClienteRapido" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title fw-bold">Registrar Cliente Express</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="formClienteRapido">
                    <div class="mb-3"><label class="fw-bold">Nombre</label><input type="text" id="rapido_nombre" class="form-control" required></div>
                    <div class="row">
                        <div class="col-6 mb-3"><label class="fw-bold">DNI (Opc.)</label><input type="text" id="rapido_dni" class="form-control"></div>
                        <div class="col-6 mb-3"><label class="fw-bold">Tel (Opc.)</label><input type="text" id="rapido_telefono" class="form-control"></div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100 fw-bold">Guardar</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
function togglePremio(val) {
    if(val === 'interno') { document.getElementById('inputInterno').classList.remove('d-none'); document.getElementById('inputExterno').classList.add('d-none'); } 
    else { document.getElementById('inputInterno').classList.add('d-none'); document.getElementById('inputExterno').classList.remove('d-none'); }
}
function seleccionarNumero(num, element) {
    document.getElementById('inputNumeroElegido').value = num;
    document.querySelectorAll('.num-box').forEach(el => el.classList.remove('num-seleccionado'));
    element.classList.add('num-seleccionado');
}
function iniciarSorteoVisual() { Swal.fire({ title: '¿Confirmar Sorteo?', text: "Se sortearán los premios.", icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, sortear' }).then((result) => { if (result.isConfirmed) { realizarSorteoBackend(); } }); }
function realizarSorteoBackend() {
    document.getElementById('zonaSorteo').classList.remove('d-none'); window.scrollTo({ top: 0, behavior: 'smooth' });
    const formData = new FormData(); formData.append('ejecutar_sorteo', true);
    fetch(window.location.href, { method: 'POST', body: formData }).then(response => response.json()).then(data => {
        if(data.error) { Swal.fire('Error', data.error, 'error'); return; }
        animarGanadores(data.ganadores, 0);
    }).catch(err => console.error(err));
}
function animarGanadores(ganadores, index) {
    if (index >= ganadores.length) { setTimeout(() => { Swal.fire({ title: '¡Sorteo Finalizado!', icon: 'success' }).then(() => location.reload()); }, 3000); return; }
    const ganador = ganadores[index];
    const display = document.getElementById('rouletteDisplay');
    document.getElementById('premioActualDisplay').innerText = `Sorteando: ${ganador.premio}`;
    let counter = 0;
    const interval = setInterval(() => {
        display.innerText = Math.floor(Math.random() * 100).toString().padStart(3, '0'); counter++;
        if (counter > 20) { clearInterval(interval); display.innerText = ganador.cliente.toUpperCase(); setTimeout(() => { animarGanadores(ganadores, index + 1); }, 2000); }
    }, 100);
}

$('#formClienteRapido').submit(function(e) {
    e.preventDefault();
    $.post('acciones/guardar_cliente_rapido.php', {
        nombre: $('#rapido_nombre').val(),
        dni: $('#rapido_dni').val(),
        telefono: $('#rapido_telefono').val()
    }, function(res) {
        if(res.status === 'success') {
            $('#select_clientes').append(new Option(res.nombre, res.id, true, true));
            $('#modalClienteRapido').modal('hide');
            Swal.fire({ icon: 'success', title: 'Cliente registrado', toast: true, position: 'top-end', timer: 1500, showConfirmButton: false });
        } else { Swal.fire('Error', res.msg, 'error'); }
    }, 'json');
});
</script>

<?php include 'includes/layout_footer.php'; ?>