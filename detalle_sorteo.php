<?php
// detalle_sorteo.php - VERSIÓN PREMIUM FINAL (RESTAURADA)
session_start();
require_once 'includes/db.php';

if (!isset($_GET['id'])) { header("Location: sorteos.php"); exit; }
$id = $_GET['id'];
// --- DETECCIÓN DE CAJA (Necesaria para registrar la venta del ticket) ---
$usuario_id = $_SESSION['usuario_id'];
$stmtCaja = $conexion->prepare("SELECT id FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta'");
$stmtCaja->execute([$usuario_id]);
$caja = $stmtCaja->fetch(PDO::FETCH_ASSOC);
// 1. OBTENER DATOS DEL SORTEO
$stmt = $conexion->prepare("SELECT * FROM sorteos WHERE id = ?");
$stmt->execute([$id]); $sorteo = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$sorteo) { header("Location: sorteos.php"); exit; }

// 2. CARGA DE PREMIOS 
$premios = $conexion->query("SELECT sp.*, p.descripcion as prod_nombre, p.precio_costo, 
    (SELECT COALESCE(SUM(ph.precio_costo * ci.cantidad),0) FROM combo_items ci 
     JOIN combos c ON c.id=ci.id_combo JOIN productos ph ON ci.id_producto=ph.id 
     WHERE c.codigo_barras=p.codigo_barras) as costo_combo 
    FROM sorteo_premios sp LEFT JOIN productos p ON sp.id_producto = p.id 
    WHERE id_sorteo = $id ORDER BY posicion ASC")->fetchAll(PDO::FETCH_ASSOC);

$tickets = $conexion->query("SELECT st.*, c.nombre, c.email, c.telefono FROM sorteo_tickets st JOIN clientes c ON st.id_cliente = c.id WHERE id_sorteo = $id ORDER BY numero_ticket ASC")->fetchAll(PDO::FETCH_ASSOC);
$clientes = $conexion->query("SELECT id, nombre, dni FROM clientes WHERE id != 1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);

$sqlProds = "SELECT p.id, p.descripcion, p.precio_costo, p.tipo, p.codigo_barras,
            (SELECT COALESCE(SUM(prod_hijo.precio_costo * ci.cantidad), 0) FROM combo_items ci JOIN combos c ON c.id = ci.id_combo JOIN productos prod_hijo ON ci.id_producto = prod_hijo.id WHERE c.codigo_barras = p.codigo_barras) as costo_combo_calc
            FROM productos p WHERE p.activo = 1 ORDER BY p.descripcion ASC";
$rawProds = $conexion->query($sqlProds)->fetchAll(PDO::FETCH_ASSOC);
$lista_prods_js = [];
foreach($rawProds as $lp) {
    $lp['costo_real'] = ($lp['tipo'] === 'combo' && $lp['costo_combo_calc'] > 0) ? $lp['costo_combo_calc'] : $lp['precio_costo'];
    $lista_prods_js[] = $lp;
}

$cantidad_vendidos = count($tickets);
$numeros_ocupados = array_column($tickets, 'numero_ticket');

// CÁLCULO DE BALANCE
$costo_premios_total = 0;
foreach($premios as $pr) { 
    if($pr['tipo'] == 'externo') {
        $costo_unit = (float)($pr['costo_externo'] ?? 0);
    } else {
        $costo_unit = (float)($pr['costo_combo'] > 0 ? $pr['costo_combo'] : ($pr['precio_costo'] ?? 0));
    }
    $costo_premios_total += $costo_unit; 
}
$recaudacion_total = (float)$sorteo['precio_ticket'] * (int)$sorteo['cantidad_tickets'];
$ganancia_neta = $recaudacion_total - $costo_premios_total;


// --- PROCESAR ACCIONES ---

// 1. CONFIRMAR RIFA
if (isset($_POST['confirmar_rifa_final_ya'])) {
    $stmt = $conexion->prepare("UPDATE sorteos SET estado = 'activo' WHERE id = ?");
    $stmt->execute([$id]);
    header("Location: detalle_sorteo.php?id=$id&msg=activado");
    exit;
}

// 2. GUARDAR EDICIÓN TOTAL
if (isset($_POST['guardar_edicion_total'])) {
    if ($sorteo['estado'] !== 'pendiente') die("Bloqueado.");
    try {
        $conexion->beginTransaction();
        $stmtU = $conexion->prepare("UPDATE sorteos SET titulo=?, fecha_sorteo=?, precio_ticket=?, cantidad_tickets=?, descripcion=? WHERE id=?");
        $stmtU->execute([$_POST['titulo'], $_POST['fecha'], $_POST['precio'], $_POST['cantidad'], $_POST['descripcion'], $id]);
        
        $conexion->prepare("DELETE FROM sorteo_premios WHERE id_sorteo = ?")->execute([$id]);
        if(!empty($_POST['premios_json'])) {
            $nuevos = json_decode($_POST['premios_json'], true);
            $stP = $conexion->prepare("INSERT INTO sorteo_premios (id_sorteo, posicion, tipo, id_producto, descripcion_externa, costo_externo) VALUES (?,?,?,?,?,?)");
            $pi = 1; 
            foreach($nuevos as $np) { 
                $t = ($np['tipo']=='manual'?'externo':'interno'); 
                $stP->execute([$id, $pi, $t, ($t=='interno'?$np['id']:NULL), ($t=='externo'?$np['nombre']:NULL), $np['costo']]); 
                $pi++; 
            }
        }
        $conexion->commit(); header("Location: detalle_sorteo.php?id=$id&msg=editado"); exit;
    } catch (Exception $e) { $conexion->rollBack(); die($e->getMessage()); }
}

// 3. VENDER TICKET (PROTEGIDO Y DEFINIDO)
if (isset($_POST['vender_ticket'])) {
    try {
        $conexion->beginTransaction();
        
        // Procesar variables necesarias
        $idUsuario = $_SESSION['usuario_id'];
        $metodo_pago = $_POST['metodo_pago'] ?? 'Efectivo';
        $codigo_venta = "RIFA-" . time();
        
        // Convertir la cadena de números "01, 05" en un array
        $input_nums = $_POST['numeros_elegidos'] ?? '';
        $numeros_elegidos = array_map('trim', explode(',', $input_nums));
        $numeros_elegidos = array_filter($numeros_elegidos); // Limpiar vacíos
        
        $total_venta = count($numeros_elegidos) * (float)$sorteo['precio_ticket'];

        $stmtTicket = $conexion->prepare("INSERT INTO sorteo_tickets (id_sorteo, id_cliente, numero_ticket) VALUES (?,?,?)");
        foreach($numeros_elegidos as $num) { 
            $stmtTicket->execute([$id, $_POST['id_cliente'], $num]); 
        }
        
        $estado_venta = $_POST['estado_venta'] ?? 'completada';
        $id_transferencia = $_POST['id_transferencia'] ?? null;

        $conexion->prepare("INSERT INTO ventas (codigo_ticket, id_caja_sesion, id_usuario, id_cliente, total, metodo_pago, estado) VALUES (?, ?, ?, ?, ?, ?, ?)")->execute([$codigo_venta, $caja['id'], $idUsuario, $_POST['id_cliente'], $total_venta, $metodo_pago, $estado_venta]);
        $venta_id = $conexion->lastInsertId();
        
        // Sumar a la caja SOLO si está completada
        if ($estado_venta === 'completada' && isset($caja['id'])) {
            if ($metodo_pago === 'Efectivo') {
                $conexion->query("UPDATE cajas_sesion SET total_ventas = total_ventas + {$total_venta}, monto_final = monto_final + {$total_venta} WHERE id = {$caja['id']}");
            } else {
                $conexion->query("UPDATE cajas_sesion SET total_ventas = total_ventas + {$total_venta} WHERE id = {$caja['id']}");
            }
        }

        if (!empty($id_transferencia) && isset($caja['id'])) {
            $stmtTr = $conexion->prepare("SELECT datos_json FROM transferencias WHERE id = ?");
            $stmtTr->execute([$id_transferencia]);
            $trData = $stmtTr->fetch(PDO::FETCH_ASSOC);
            if ($trData) {
                $jsonTr = json_decode($trData['datos_json'], true);
                $jsonTr['estado'] = ($estado_venta === 'pendiente_transferencia') ? 'pendiente' : 'completada';
                $jsonTr['id_venta'] = $venta_id;
                $jsonTr['es_sorteo'] = true;
                $jsonTr['total_venta'] = $total_venta;
                $jsonTr['id_caja'] = $caja['id'];
                $conexion->prepare("UPDATE transferencias SET datos_json = ? WHERE id = ?")->execute([json_encode($jsonTr, JSON_UNESCAPED_UNICODE), $id_transferencia]);
            }
        }
        
        $conexion->commit(); 
        header("Location: detalle_sorteo.php?id=$id&msg=ticket_ok"); 
        exit;
    } catch (Exception $e) { 
        $conexion->rollBack(); 
        die("Error al procesar ticket: " . $e->getMessage()); 
    }
}

// 4. EJECUTAR SORTEO (LA RULETA RESTAURADA)
if (isset($_POST['ejecutar_sorteo'])) {
    header('Content-Type: application/json');
    try {
        $conexion->beginTransaction();
        $tks = $conexion->query("SELECT st.*, c.nombre as cliente, c.telefono, c.email FROM sorteo_tickets st JOIN clientes c ON st.id_cliente = c.id WHERE id_sorteo = $id")->fetchAll(PDO::FETCH_ASSOC);
        
        if(count($tks) == 0) {
            echo json_encode(['error' => 'No hay tickets vendidos para sortear.']); exit;
        }
        
        $prems = $conexion->query("SELECT sp.*, p.descripcion as prod_nombre FROM sorteo_premios sp LEFT JOIN productos p ON sp.id_producto = p.id WHERE id_sorteo = $id ORDER BY posicion ASC")->fetchAll(PDO::FETCH_ASSOC);
        
        shuffle($tks); // Mezclamos
        $ganadores = [];
        
        foreach($prems as $i => $pr) {
            if(isset($tks[$i])) {
                $ganador = $tks[$i];
                $desc_premio = $pr['tipo'] == 'externo' ? $pr['descripcion_externa'] : $pr['prod_nombre'];
                $ganadores[] = [
                    'posicion' => $pr['posicion'],
                    'premio' => $desc_premio,
                    'cliente' => $ganador['cliente'],
                    'telefono' => $ganador['telefono'],
                    'email' => $ganador['email'],
                    'ticket' => $ganador['numero_ticket']
                ];
            }
        }
        $ganadores_json = json_encode($ganadores);
        $conexion->prepare("UPDATE sorteos SET estado = 'finalizado', ganadores_json = ? WHERE id = ?")->execute([$ganadores_json, $id]);
        $conexion->commit();
        echo json_encode(['ganadores' => $ganadores]); exit;
    } catch (Exception $e) {
        $conexion->rollBack();
        echo json_encode(['error' => 'Error BD: ' . $e->getMessage()]); exit;
    }
}

include 'includes/layout_header.php'; ?>

<style>
    .roulette-container { border: 5px solid #102A57; border-radius: 20px; background: #1a1a1a; height: 120px; display: flex; align-items: center; justify-content: center; overflow: hidden; box-shadow: inset 0 0 20px #000; }
    .roulette-window { font-size: 4rem; font-weight: 900; color: #fff; font-family: 'Courier New', monospace; }
    .grid-numeros { display: grid; grid-template-columns: repeat(auto-fill, minmax(50px, 1fr)); gap: 10px; max-height: 450px; overflow-y: auto; padding: 15px; border: 1px solid #ddd; border-radius: 15px; background: #fff; }
    .num-box { aspect-ratio: 1; display: flex; align-items: center; justify-content: center; border-radius: 12px; font-weight: bold; cursor: pointer; border: 1px solid #dee2e6; transition: 0.2s; }
    .num-libre { background: #fdfdfd; } .num-libre:hover { background: #eef2f7; transform: scale(1.05); }
    .num-ocupado { background: #dc3545; color: white; cursor: not-allowed; opacity: 0.7; }
    .num-seleccionado { background: #198754 !important; color: white !important; transform: scale(1.15); }
</style>

<div class="container py-4">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 gap-3">
        <div>
            <h2 class="fw-bold text-primary mb-0" style="font-size: 1.6rem;">
                <i class="bi bi-ticket-perforated me-2"></i><?php echo htmlspecialchars($sorteo['titulo']); ?>
                <span class="badge <?php echo ($sorteo['estado']=='activo'?'bg-success':($sorteo['estado']=='pendiente'?'bg-warning text-dark':'bg-secondary')); ?> fs-6 align-middle ms-2 shadow-sm"><?php echo strtoupper($sorteo['estado']); ?></span>
            </h2>
        </div>
        <div class="d-flex flex-wrap gap-2 justify-content-end">
            <a href="sorteos.php" class="btn text-white fw-bold rounded-pill px-4 shadow-sm" style="background-color: #102A57;">
                <i class="bi bi-arrow-left me-2"></i>VOLVER
            </a>
            
            <?php if($sorteo['estado'] == 'pendiente'): ?>
                <button class="btn btn-outline-primary fw-bold rounded-pill px-4 shadow-sm" onclick="abrirModalEdicionTotal()"><i class="bi bi-pencil-square me-2"></i>EDITAR</button>
                <button class="btn btn-success fw-bold rounded-pill px-4 shadow" onclick="lanzarConfirmacionFinal()"><i class="bi bi-check-circle-fill me-2"></i>CONFIRMAR</button>
            <?php elseif($sorteo['estado'] == 'activo'): ?>
                <button class="btn btn-warning fw-bold shadow rounded-pill px-4 text-dark" onclick="iniciarSorteoVisual()"><i class="bi bi-trophy-fill me-2"></i>¡SORTEAR!</button>
            <?php endif; ?>
        </div>
    </div>

    <div id="zonaSorteo" class="mb-5 d-none">
        <div class="card bg-dark text-white text-center p-5 rounded-4 shadow-lg border-0">
            <h3 class="mb-4 text-warning fw-bold">¡BUSCANDO GANADOR!</h3>
            <div class="roulette-container mb-4"><div class="roulette-window" id="rouletteDisplay">000</div></div>
            <h4 id="premioActualDisplay" class="text-info fw-bold"></h4>
        </div>
    </div>

    <?php if($sorteo['estado'] == 'finalizado' && $sorteo['ganadores_json']): 
        $ganadoresData = json_decode($sorteo['ganadores_json'], true);
    ?>
    <div class="card border-warning mb-5 shadow rounded-4 overflow-hidden">
        <div class="card-header bg-warning text-dark fw-bold text-center py-3">🏆 RESULTADOS OFICIALES DEL SORTEO</div>
        <div class="card-body p-4"><div class="row text-center g-4">
            <?php foreach($ganadoresData as $g): 
                $num_wa = preg_replace('/[^0-9]/', '', $g['telefono'] ?? '');
                $msg_wa = "¡Hola ".$g['cliente']."! %F0%9F%8F%86 Sos el ganador del sorteo ".$sorteo['titulo'].". Ganaste: ".$g['premio'].". Te esperamos %F0%9F%8E%89";
                $wa_link = !empty($num_wa) ? "https://wa.me/".$num_wa."?text=".$msg_wa : "#";
            ?><div class="col-md-4">
                <div class="card h-100 p-3 border-warning rounded-4 bg-white shadow-sm">
                    <div class="display-6 mb-2">🥇</div>
                    <h6 class="fw-bold text-muted small">PUESTO #<?php echo $g['posicion']; ?></h6>
                    <h4 class="text-primary fw-bold text-uppercase"><?php echo $g['cliente']; ?></h4>
                    <p class="text-muted fw-bold mb-2">Ticket #<?php echo str_pad($g['ticket'], 3, '0', STR_PAD_LEFT); ?></p>
                    <div class="p-2 bg-success bg-opacity-10 text-success rounded-3 border border-success fw-bold mb-3"><?php echo $g['premio']; ?></div>
                    <?php if($wa_link !== "#"): ?><a href="<?php echo $wa_link; ?>" target="_blank" class="btn btn-success btn-sm w-100 rounded-pill fw-bold py-2"><i class="bi bi-whatsapp me-2"></i>NOTIFICAR</a><?php endif; ?>
                </div>
            </div><?php endforeach; ?>
        </div></div>
    </div>
    <?php endif; ?>
    <div class="row g-4 align-items-stretch mb-5">
        
        <div class="col-md-4 d-flex flex-column">
            
            <div class="card shadow-sm border-0 rounded-4 mb-4">
                <div class="card-header bg-white fw-bold py-3 border-bottom"><i class="bi bi-gift me-2 text-primary"></i>1. Premios y Balance</div>
                <div class="card-body">
                    <ul class="list-group list-group-flush mb-4">
                        <?php foreach($premios as $p): 
                            $c_it = ($p['tipo'] == 'externo' ? (float)($p['costo_externo'] ?? 0) : ($p['costo_combo'] > 0 ? $p['costo_combo'] : ($p['precio_costo'] ?? 0)));
                        ?>
                        <li class="list-group-item px-0 py-3 border-bottom">
                            <div class="d-flex justify-content-between align-items-center">
                                <div><span class="badge bg-primary rounded-circle me-2"><?php echo $p['posicion']; ?></span><span class="fw-bold"><?php echo $p['prod_nombre'] ?? $p['descripcion_externa']; ?></span></div>
                            </div>
                            <div class="text-muted small ms-4 mt-1">Costo Unitario: $<?php echo number_format($c_it, 2, ',', '.'); ?></div>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <div class="bg-light p-4 rounded-4 border">
                        <div class="d-flex justify-content-between mb-2 small text-muted uppercase"><span>Ingreso Bruto:</span><span class="fw-bold text-success">$<?php echo number_format($recaudacion_total, 0, ',', '.'); ?></span></div>
                        <div class="d-flex justify-content-between mb-2 small text-muted uppercase"><span>Gasto Total Stock:</span><span class="fw-bold text-danger">-$<?php echo number_format($costo_premios_total, 0, ',', '.'); ?></span></div>
                        <hr><div class="d-flex justify-content-between align-items-center"><span class="fw-bold text-dark">GANANCIA NETA:</span><span class="fs-5 fw-bold text-primary">$<?php echo number_format($ganancia_neta, 0, ',', '.'); ?></span></div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm border-0 rounded-4 text-white text-center flex-grow-1 d-flex flex-column justify-content-center" style="background: linear-gradient(135deg, #102A57 0%, #1a458f 100%) !important;">
                <div class="card-body p-4 d-flex flex-column justify-content-center align-items-center">
                    <i class="bi bi-tags display-5 opacity-50 mb-3"></i>
                    <h6 class="fw-bold text-uppercase mb-1 tracking-wider text-white-50">Valor por Número</h6>
                    <h1 class="display-3 fw-bold mb-0 text-white">$<?php echo number_format($sorteo['precio_ticket'], 0, ',', '.'); ?></h1>
                    
                    <?php if($sorteo['estado'] == 'activo'): ?>
                        <div class="mt-auto pt-4 w-100">
                            <div class="p-3 rounded-3 bg-white bg-opacity-10 border border-white border-opacity-25 w-100">
                                <p class="small opacity-100 mb-0 fw-bold"><i class="bi bi-arrow-right-circle me-1"></i> Haga clic en la grilla para cobrar</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <div class="col-md-8 d-flex flex-column">
            
            <div class="card shadow-sm border-0 rounded-4 flex-grow-1 d-flex flex-column">
                <div class="card-header bg-white fw-bold py-3">2. Terminal de Cobro y Grilla (<?php echo $cantidad_vendidos; ?> Vendidos)</div>
                <div class="card-body p-4 d-flex flex-column">
                    <?php if($sorteo['estado'] == 'activo'): ?>
                    
                        <form method="POST" id="formVenta" class="bg-light p-3 rounded-4 border shadow-sm mb-4">
                            <input type="hidden" name="estado_venta" id="hidden_estado_venta" value="completada">
<input type="hidden" name="id_transferencia" id="hidden_id_transferencia" value="">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-4">
                                    <label class="small fw-bold text-muted uppercase mb-1">Buscar Cliente</label>
                                    <div class="d-flex shadow-sm rounded-3">
                                        <div class="flex-grow-1">
                                            <select name="id_cliente" id="select_clientes" class="form-select fw-bold border-end-0 rounded-start-3 rounded-end-0" style="width: 100%;">
                                                <option value="">Buscar por nombre o DNI...</option>
                                                <?php foreach($clientes as $c): 
                                                    $dniTexto = !empty($c['dni']) ? ' - DNI: ' . $c['dni'] : '';
                                                ?>
                                                    <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['nombre']) . $dniTexto; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button class="btn btn-success border-0 px-3 rounded-end-3" type="button" onclick="abrirModalClienteRapido()" title="Crear Cliente Nuevo">
                                            <i class="bi bi-person-plus-fill"></i>
                                        </button>
                                    </div>
                                </div>
                                
                                <div class="col-md-2">
                                    <label class="small fw-bold text-muted uppercase mb-1">Nº Tickets</label>
                                    <input type="text" name="numeros_elegidos" id="inputNumeroElegido" class="form-control text-center fw-bold fs-6 text-primary bg-white shadow-sm" readonly placeholder="--">
                                </div>
                                
                                <div class="col-md-3">
                                    <label class="small fw-bold text-muted uppercase mb-1">Forma de Pago</label>
                                    <select name="metodo_pago" id="metodo_pago_sorteo" class="form-select fw-bold shadow-sm">
                                        <option value="Efectivo">💵 Efectivo</option>
                                        <option value="mercadopago">📱 QR Billeteras virtuales</option>
                                        <option value="Point">💳 Tarjeta (Posnet)</option>
                                        <option value="Transferencia">🏦 Transferencia</option>
                                    </select>
                                </div>

                                <div class="col-md-3">
                                    <button type="button" id="btn-cobrar-ticket" class="btn btn-primary w-100 fw-bold rounded-3 shadow py-2" onclick="validarVentaPro()">
                                        <i class="bi bi-cash-coin me-1"></i>COBRAR
                                    </button>
                                    <button type="button" id="btn-sync-mp-sorteo" class="btn btn-info text-white w-100 fw-bold rounded-3 shadow py-2 d-none" onclick="enviarMontoAMercadoPagoSorteo()">
                                        <i class="bi bi-qr-code-scan me-1"></i>AL QR
                                    </button>
                                    <button type="button" id="btn-sync-point-sorteo" class="btn btn-primary text-white w-100 fw-bold rounded-3 shadow py-2 d-none" onclick="enviarMontoAPointSorteo()">
                                        <i class="bi bi-calculator me-1"></i>AL POSNET
                                    </button>
                                    <div id="btn-escaner-sorteo" class="d-none">
                                        <button type="button" class="btn btn-info w-100 fw-bold text-white shadow py-2 mb-1" onclick="abrirEscanerTransferenciaSorteo()">
                                            <i class="bi bi-camera-video fs-6 me-1"></i> LECTOR IA
                                        </button>
                                        <button type="button" class="btn btn-link w-100 text-muted p-0 m-0 text-decoration-none" style="font-size: 0.75rem;" onclick="$('#submitReal').click()">
                                            Cobro manual
                                        </button>
                                    </div>
                                    <input type="submit" name="vender_ticket" id="submitReal" style="display:none;">
                                </div>
                            </div>
                        </form>

                        <div class="flex-grow-1">
                            <div class="grid-numeros h-100" style="max-height: 520px;">
                                <?php for($i=1; $i<=$sorteo['cantidad_tickets']; $i++): 
                                    $ocupado = in_array($i, $numeros_ocupados); 
                                ?>
                                    <div class="num-box <?php echo $ocupado ? 'num-ocupado' : 'num-libre'; ?>" onclick="<?php echo $ocupado ? '' : "seleccionarNumeroIndividual($i, this)"; ?>">
                                        <?php echo str_pad($i, 2, '0', STR_PAD_LEFT); ?>
                                    </div>
                                <?php endfor; ?>
                            </div>
                        </div>

                    <?php else: ?>
                        <div class="text-center py-5 opacity-50 d-flex flex-column justify-content-center h-100">
                            <i class="bi bi-lock display-1"></i>
                            <h4 class="mt-3 fw-bold">RIFA BLOQUEADA</h4>
                            <p>Debe confirmar la rifa arriba para vender tickets.</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>                    
    
</div>

<div class="modal fade" id="modalEditarPro" tabindex="-1"><div class="modal-dialog modal-xl modal-dialog-centered"><form method="POST" class="modal-content border-0 shadow-lg rounded-4"><input type="hidden" name="premios_json" id="edit_prems_json"><div class="modal-header bg-primary text-white border-0 py-3"><h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-2"></i>Editar Sorteo Completo</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div><div class="modal-body p-4"><div class="row"><div class="col-md-4 border-end">
    <div class="mb-3"><label class="small fw-bold">Título</label><input type="text" name="titulo" id="ed_titulo" class="form-control fw-bold" value="<?php echo $sorteo['titulo']; ?>" required></div>
    <div class="mb-3"><label class="small fw-bold">Descripción</label><textarea name="descripcion" id="ed_desc" class="form-control" rows="2"><?php echo $sorteo['descripcion']; ?></textarea></div>
    <div class="row"><div class="col-6 mb-3"><label class="small fw-bold">Precio ($)</label><input type="number" name="precio" id="ed_precio" class="form-control fw-bold" step="0.01" value="<?php echo $sorteo['precio_ticket']; ?>" oninput="calcE()"></div><div class="col-6 mb-3"><label class="small fw-bold">Tickets</label><input type="number" name="cantidad" id="ed_cantidad" class="form-control fw-bold" value="<?php echo $sorteo['cantidad_tickets']; ?>" oninput="calcE()"></div></div>
    <div class="mb-3"><label class="small fw-bold">Fecha</label><input type="date" name="fecha" id="ed_fecha" class="form-control fw-bold" value="<?php echo date('Y-m-d', strtotime($sorteo['fecha_sorteo'])); ?>" required></div>
</div><div class="col-md-8">
    <div class="d-flex justify-content-between align-items-center mb-3"><h6 class="text-primary fw-bold m-0 uppercase small">Gestión de Premios</h6><button type="button" class="btn btn-sm btn-outline-success fw-bold" onclick="addFilaE()"><i class="bi bi-plus-lg"></i> AGREGAR</button></div>
    <div id="cont_prems_edit" style="max-height:300px; overflow-y:auto; padding:10px; border:1px solid #eee; border-radius:10px;"></div>
    <div class="p-3 bg-light rounded-4 border mt-3 row g-0">
    <div class="col-6 text-center border-end">
        <span class="d-block text-muted small fw-bold uppercase">Costo Premios</span>
        <span class="fs-5 fw-bold text-danger" id="ed_costo_total">$0</span>
    </div>
    <div class="col-6 text-center">
        <span class="d-block text-muted small fw-bold uppercase">Ganancia Estimada</span>
        <span class="fs-5 fw-bold text-success" id="ed_ganancia_total">$0</span>
    </div>
</div>
</div></div></div><div class="modal-footer border-0 bg-light"><button type="submit" name="guardar_edicion_total" class="btn btn-primary w-100 py-3 rounded-pill fw-bold shadow" onclick="prepE()">GUARDAR CAMBIOS TOTALES</button></div></form></div></div>


<form id="formConfirmarFinal" method="POST"><input type="hidden" name="accion_confirmar" value="1"></form>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    const catalogo = <?php echo json_encode($lista_prods_js); ?>;
    const premiosIniciales = <?php echo json_encode($premios); ?>;
    let contE = 0;

    function abrirModalEdicionTotal() { $('#cont_prems_edit').empty(); contE = 0; premiosIniciales.forEach(p => { addFilaE(p); }); $('#modalEditarPro').modal('show'); calcE(); }
    function addFilaE(data = null) {
        contE++;
        let opts = '<option value="0" data-costo="0">Elegir Stock...</option>';
        catalogo.forEach(c => { let sel = (data && data.id_producto == c.id) ? 'selected' : ''; opts += `<option value="${c.id}" data-costo="${c.costo_real}" ${sel}>${c.descripcion}</option>`; });
        const esM = (data && data.tipo === 'externo');
        const costVal = (data ? (data.tipo == 'externo' ? data.costo_externo : (data.costo_combo > 0 ? data.costo_combo : data.precio_costo)) : 0);

        const html = `<div class="fila-e d-flex gap-2 mb-2 align-items-center" id="f_e_${contE}"><div class="fw-bold text-muted small">#${contE}</div><div style="width:100px;"><select class="form-select form-select-sm t-e" onchange="chE(${contE}, this)"><option value="interno" ${!esM?'selected':''}>Stock</option><option value="manual" ${esM?'selected':''}>Manual</option></select></div><div class="flex-grow-1 ${esM?'d-none':''}" id="d_p_e_${contE}"><select class="form-select form-select-sm s-e" onchange="uCE(${contE}, this)">${opts}</select></div><div class="flex-grow-1 ${!esM?'d-none':''}" id="d_m_e_${contE}"><input type="text" class="form-control form-control-sm i-m-e" value="${data?data.descripcion_externa:''}" placeholder="Premio..."></div><div class="input-group input-group-sm" style="width:110px;"><span class="input-group-text">$</span><input type="number" step="0.01" class="form-control c-e" id="c_e_${contE}" value="${costVal}" oninput="calcE()" ${!esM?'readonly':''}></div><button type="button" class="btn btn-sm btn-outline-danger border-0" onclick="$('#f_e_${contE}').remove(); calcE();"><i class="bi bi-trash"></i></button></div>`;
        $('#cont_prems_edit').append(html);
    }
    function chE(id, s) { if(s.value==='manual'){ $(`#d_p_e_${id}`).addClass('d-none'); $(`#d_m_e_${id}`).removeClass('d-none'); $(`#c_e_${id}`).prop('readonly', false).val(0); } else { $(`#d_p_e_${id}`).removeClass('d-none'); $(`#d_m_e_${id}`).addClass('d-none'); $(`#c_e_${id}`).prop('readonly', true).val(0); } calcE(); }
    function uCE(id, s) { $(`#c_e_${id}`).val($(s).find(':selected').data('costo')); calcE(); }
    function calcE() {
        let rec = (parseFloat($('#ed_precio').val()) || 0) * (parseInt($('#ed_cantidad').val()) || 0);
        let cos = 0; $('.c-e').each(function(){ cos += parseFloat($(this).val()) || 0; });
        let gan = rec - cos;
        $('#ed_costo_total').text('$' + cos.toLocaleString('es-AR'));
        $('#ed_ganancia_total').text('$' + gan.toLocaleString('es-AR'));
        $('#ed_costo_total').addClass('text-danger');
        $('#ed_ganancia_total').css('color', gan >= 0 ? '#198754' : '#dc3545');
    }
    function prepE() { let prems = []; $('.fila-e').each(function(){ let t = $(this).find('.t-e').val(); let obj = { tipo: t, costo: $(this).find('.c-e').val() }; if(t==='interno') { let s = $(this).find('.s-e'); obj.id = s.val(); obj.nombre = s.find(':selected').text(); } else { obj.nombre = $(this).find('.i-m-e').val(); } prems.push(obj); }); $('#edit_prems_json').val(JSON.stringify(prems)); }
    let numerosSeleccionados = [];
    let precioTicketUnitario = <?php echo $sorteo['precio_ticket']; ?>;

    function seleccionarNumeroIndividual(n, el) { 
        const index = numerosSeleccionados.indexOf(n);
        if (index > -1) {
            // Si ya estaba seleccionado, lo desmarcamos
            numerosSeleccionados.splice(index, 1);
            $(el).removeClass('num-seleccionado');
        } else {
            // Si no estaba, lo sumamos
            numerosSeleccionados.push(n);
            $(el).addClass('num-seleccionado');
        }
        
        // Ordenamos los números de menor a mayor
        numerosSeleccionados.sort((a,b) => a - b);
        $('#inputNumeroElegido').val(numerosSeleccionados.join(', '));
        
        // Actualizamos el botón de cobrar sumando el total
        let total = numerosSeleccionados.length * precioTicketUnitario;
        if(numerosSeleccionados.length > 0) {
            $('#btn-cobrar-ticket').html(`<i class="bi bi-cash-coin me-1"></i>COBRAR $${total.toLocaleString('es-AR')}`);
        } else {
            $('#btn-cobrar-ticket').html(`<i class="bi bi-cash-coin me-1"></i>COBRAR`);
        }
    }

    function lanzarConfirmacionFinal() {
        Swal.fire({
            title: '¿Confirmar Sorteo?',
            text: "Se activarán las ventas y no podrás editar precios ni premios.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Sí, activar ahora',
            confirmButtonColor: '#198754',
            cancelButtonText: 'Cancelar'
        }).then((r) => {
            if (r.isConfirmed) {
                const f = document.createElement('form');
                f.method = 'POST';
                f.innerHTML = '<input type="hidden" name="confirmar_rifa_final_ya" value="1">';
                document.body.appendChild(f);
                f.submit();
            }
        });
    }

    // Alternar botones según el método de pago
    $('#metodo_pago_sorteo').change(function() {
        $('#btn-cobrar-ticket, #btn-sync-mp-sorteo, #btn-sync-point-sorteo, #btn-escaner-sorteo').addClass('d-none');
        
        if ($(this).val() === 'mercadopago') {
            $('#btn-sync-mp-sorteo').removeClass('d-none');
        } else if ($(this).val() === 'Point') {
            $('#btn-sync-point-sorteo').removeClass('d-none');
        } else if ($(this).val() === 'Transferencia') {
            $('#btn-escaner-sorteo').removeClass('d-none');
        } else {
            $('#btn-cobrar-ticket').removeClass('d-none');
        }
    });

    // --- LÓGICA DE COBRO LIMPIA SIN RADAR ---
    function validarVentaPro() { 
        if(numerosSeleccionados.length === 0) { Swal.fire('Error', 'Elegí al menos un número en la grilla.', 'error'); return; } 
        
        let idCliente = $('#select_clientes').val();
        if(!idCliente) { Swal.fire('Atención', 'Debés buscar y seleccionar un cliente primero.', 'warning'); return; }
        
        let numStr = numerosSeleccionados.join(', ');
        let metodo = $('#metodo_pago_sorteo').val();
        let metodoNombre = $('#metodo_pago_sorteo option:selected').text();
        let precioTotal = numerosSeleccionados.length * precioTicketUnitario;

        Swal.fire({
            title: '¿Confirmar Pago?',
            html: `Tickets: <b class="text-primary fs-5">${numStr}</b><br>Total: <b class="text-success fs-4">$${precioTotal.toLocaleString('es-AR')}</b><br>Método: <b>${metodoNombre}</b><br><br>¿Ya recibiste el dinero?`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, cobrar tickets',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#198754'
        }).then((result) => {
            if (result.isConfirmed) { $('#submitReal').click(); }
        });
    }

    // --- QR DE MERCADO PAGO EN SORTEO ---
    let intervaloMPSorteo = null;
    function enviarMontoAMercadoPagoSorteo() {
        if(numerosSeleccionados.length === 0){ Swal.fire('Error', 'Elegí al menos un número.', 'error'); return; } 
        let idCliente = $('#select_clientes').val();
        if(!idCliente) { Swal.fire('Atención', 'Debés buscar y seleccionar un cliente primero.', 'warning'); return; }
        
        let totalRifa = numerosSeleccionados.length * precioTicketUnitario; 
        const btn = $('#btn-sync-mp-sorteo');
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Esperando...');

        $.post('acciones/mp_sync.php', { total: totalRifa }, function(res) {
            if(res.status === 'success') {
                const ref = res.referencia;
                Swal.fire({ icon: 'info', title: 'QR Activado', text: 'El cliente debe escanear el QR en el mostrador.', showConfirmButton: false, showCancelButton: true, cancelButtonText: '🛑 Cancelar', cancelButtonColor: '#dc3545', allowOutsideClick: false }).then((result) => {
                    if (result.dismiss === Swal.DismissReason.cancel) {
                        if(intervaloMPSorteo) clearInterval(intervaloMPSorteo);
                        btn.prop('disabled', false).html('<i class="bi bi-qr-code-scan me-1"></i>AL QR');
                    }
                });

                if(intervaloMPSorteo) clearInterval(intervaloMPSorteo);
                intervaloMPSorteo = setInterval(function() {
                    $.getJSON('acciones/verificar_pago_mp.php', { referencia: ref }, function(statusRes) {
                        if(statusRes.estado === 'pagado') {
                            clearInterval(intervaloMPSorteo);
                            Swal.close();
                            $('#submitReal').click(); 
                        }
                    });
                }, 3000);
            } else {
                btn.prop('disabled', false).html('<i class="bi bi-qr-code-scan me-1"></i>AL QR');
                Swal.fire('Error', res.msg, 'error');
            }
        }, 'json').fail(function() {
            btn.prop('disabled', false).html('<i class="bi bi-qr-code-scan me-1"></i>AL QR');
            Swal.fire('Error', 'No se conectó con MercadoPago', 'error');
        });
    }

    // --- POSNET FÍSICO (MERCADO PAGO POINT) ---
    let intervaloPointSorteo = null;
    function enviarMontoAPointSorteo() {
        if(numerosSeleccionados.length === 0){ Swal.fire('Error', 'Elegí al menos un número.', 'error'); return; } 
        let idCliente = $('#select_clientes').val();
        if(!idCliente) { Swal.fire('Atención', 'Debés buscar y seleccionar un cliente primero.', 'warning'); return; }
        
        let totalRifa = numerosSeleccionados.length * precioTicketUnitario; 
        const btn = $('#btn-sync-point-sorteo');
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Mandando...');

        $.post('acciones/mp_point_sync.php', { total: totalRifa }, function(res) {
            if(res.status === 'success') {
                const paymentIntentId = res.payment_intent_id;
                Swal.fire({ icon: 'info', title: 'Posnet Activado', text: 'Pase la tarjeta por el Posnet físico.', showConfirmButton: false, showCancelButton: true, cancelButtonText: '🛑 Cancelar', cancelButtonColor: '#dc3545', allowOutsideClick: false }).then((result) => {
                    if (result.dismiss === Swal.DismissReason.cancel) {
                        if(intervaloPointSorteo) clearInterval(intervaloPointSorteo);
                        $.post('acciones/mp_point_cancelar.php', { intent_id: paymentIntentId });
                        btn.prop('disabled', false).html('<i class="bi bi-calculator me-1"></i>AL POSNET');
                    }
                });

                if(intervaloPointSorteo) clearInterval(intervaloPointSorteo);
                intervaloPointSorteo = setInterval(function() {
                    $.getJSON('acciones/verificar_pago_point.php', { intent_id: paymentIntentId }, function(statusRes) {
                        if(statusRes.estado === 'FINISHED') { 
                            clearInterval(intervaloPointSorteo); Swal.close(); $('#submitReal').click(); 
                        } else if (statusRes.estado === 'CANCELED' || statusRes.estado === 'ERROR') {
                            clearInterval(intervaloPointSorteo);
                            btn.prop('disabled', false).html('<i class="bi bi-calculator me-1"></i>AL POSNET');
                            Swal.fire('Atención', 'El pago fue cancelado en el Posnet.', 'warning');
                        }
                    });
                }, 3000);
            } else {
                btn.prop('disabled', false).html('<i class="bi bi-calculator me-1"></i>AL POSNET');
                Swal.fire('Error', res.msg, 'error');
            }
        }, 'json').fail(function() {
            btn.prop('disabled', false).html('<i class="bi bi-calculator me-1"></i>AL POSNET');
            Swal.fire('Error', 'Falla de red con el Posnet', 'error');
        });
    }

    function iniciarSorteoVisual() {
        Swal.fire({
            title: '¿Comenzar el Sorteo?',
            text: "Se seleccionarán los ganadores y se mostrará la animación en pantalla.",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '¡Sí, sortear!',
            confirmButtonColor: '#ffc107'
        }).then((result) => {
            if (result.isConfirmed) {
                realizarSorteoReal();
            }
        });
    }

    function realizarSorteoReal() {
        const zona = document.getElementById('zonaSorteo');
        const display = document.getElementById('rouletteDisplay');
        zona.classList.remove('d-none');
        zona.scrollIntoView({ behavior: 'smooth' });
        display.innerText = "ESPERANDO...";

        const fd = new FormData();
        fd.append('ejecutar_sorteo', true);
        
        fetch(window.location.href, { method: 'POST', body: fd })
        .then(res => res.json())
        .then(data => {
            if(data.error) { 
                Swal.fire('Error', data.error, 'error'); 
                return; 
            }
            animarGanadoresSorteo(data.ganadores, 0);
        }).catch(err => {
            Swal.fire('Error', 'Problema al conectar con el servidor', 'error');
        });
    }

    function animarGanadoresSorteo(ganadores, index) {
        if (index >= ganadores.length) {
            let resumenHTML = '<div class="text-start mt-3 p-3 bg-light rounded border small">';
            ganadores.forEach(g => {
                resumenHTML += `<p class="mb-2 border-bottom pb-1">
                    ✅ <b>Premio #${g.posicion} (${g.premio}):</b><br>
                    👤 Ganador: ${g.cliente}<br>
                    📧 Correo: ${g.email || 'No registrado'}
                </p>`;
            });
            resumenHTML += '</div>';

            setTimeout(() => {
                Swal.fire({
                    title: '¡Sorteo Finalizado!',
                    html: `Se procesaron todos los premios con éxito.<br>${resumenHTML}<br><br><b>Redireccionando en 10 segundos...</b>`,
                    icon: 'success',
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: '#102A57',
                    timer: 10000,
                    timerProgressBar: true,
                    allowOutsideClick: false
                }).then(() => {
                    window.location.href = 'sorteos.php';
                });
            }, 1500);
            return;
        }

        const g = ganadores[index];
        const display = document.getElementById('rouletteDisplay');
        document.getElementById('premioActualDisplay').innerText = `Sorteando: ${g.premio}`;
        
        let counter = 0;
        const casinoInterval = setInterval(() => {
            display.innerText = Math.floor(Math.random() * 99 + 1).toString().padStart(3, '0');
            counter++;

            if (counter > 25) { 
                clearInterval(casinoInterval);
                display.innerText = g.cliente.toUpperCase();
                
                let fde = new FormData();
                fde.append('id_sorteo', '<?php echo $id; ?>');
                fde.append('cliente', g.cliente);
                fde.append('email', g.email);
                fde.append('premio', g.premio);
                fde.append('puesto', g.posicion);
                fde.append('ticket', g.ticket);
                fetch('acciones/enviar_email_ganador.php', { method: 'POST', body: fde });

                setTimeout(() => { 
                    animarGanadoresSorteo(ganadores, index + 1); 
                }, 3500);
            }
        }, 80);
    }

    function abrirModalClienteRapido() {
        Swal.fire({
            title: '<i class="bi bi-person-plus-fill text-success"></i> Cliente Rápido',
            html: `
                <div class="text-start">
                    <div class="mb-2">
                        <label class="small fw-bold text-muted">Nombre Completo *</label>
                        <input type="text" id="sa2-rapido-nombre" class="form-control fw-bold" placeholder="Ej: Juan Perez" required onkeyup="generarUsuarioRapido()">
                    </div>
                    <div class="mb-2">
                        <label class="small fw-bold text-muted">Email (Opcional - Para tickets)</label>
                        <input type="email" id="sa2-rapido-email" class="form-control" placeholder="correo@ejemplo.com">
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="small fw-bold text-muted">DNI / CUIT</label>
                            <input type="number" id="sa2-rapido-dni" class="form-control" placeholder="Opcional">
                        </div>
                        <div class="col-6">
                            <label class="small fw-bold text-muted">Usuario Web</label>
                            <input type="text" id="sa2-rapido-usuario" class="form-control bg-light text-primary fw-bold" readonly>
                        </div>
                    </div>
                    <div class="mb-2">
                        <label class="small fw-bold text-muted">WhatsApp (Ej: 54911...)</label>
                        <input type="number" id="sa2-rapido-telefono" class="form-control" placeholder="Opcional">
                    </div>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-save"></i> Guardar y Seleccionar',
            cancelButtonText: 'Cancelar',
            confirmButtonColor: '#198754',
            preConfirm: () => {
                const nombre = document.getElementById('sa2-rapido-nombre').value.trim();
                const email = document.getElementById('sa2-rapido-email').value.trim();
                const dni = document.getElementById('sa2-rapido-dni').value.trim();
                const usuario = document.getElementById('sa2-rapido-usuario').value.trim();
                const telefono = document.getElementById('sa2-rapido-telefono').value.trim();
                
                if (!nombre) {
                    Swal.showValidationMessage('El nombre es obligatorio');
                    return false;
                }
                
                return $.post('acciones/cliente_rapido.php', {
                    nombre: nombre, email: email, dni: dni, usuario: usuario, whatsapp: telefono
                }).then(res => {
                    if (res.status !== 'success') { throw new Error(res.msg || 'Error al guardar en la Base de Datos'); }
                    return res;
                }).catch(err => {
                    Swal.showValidationMessage(err.message || 'Error de conexión');
                });
            }
        }).then((result) => {
            if (result.isConfirmed && result.value && result.value.status === 'success') {
                // Agregamos al select y lo dejamos seleccionado automáticamente
                $('#select_clientes').append(new Option(result.value.nombre, result.value.id, true, true));
                Swal.fire({ icon: 'success', title: '¡Creado!', text: 'El cliente ya está cargado para el sorteo.', timer: 2000, showConfirmButton: false });
            }
        });
    }

    function generarUsuarioRapido() {
        let nombreVal = document.getElementById('sa2-rapido-nombre').value.trim().toLowerCase();
        nombreVal = nombreVal.replace(/ñ/g, 'n').replace(/Ñ/g, 'n');
        let partes = nombreVal.split(' ').filter(p => p.length > 0);
        let userSugerido = '';
        if (partes.length === 1) { userSugerido = partes[0]; } 
        else if (partes.length >= 2) { userSugerido = partes[0].charAt(0) + partes[partes.length - 1]; }
        userSugerido = userSugerido.normalize("NFD").replace(/[\u0300-\u036f]/g, "").replace(/[^a-z0-9]/g, '');
        document.getElementById('sa2-rapido-usuario').value = userSugerido;
    }
</script>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
    .select2-container .select2-selection--single {
        height: 38px !important;
        border: 1px solid #dee2e6 !important;
        border-right: none !important;
        border-radius: 0.375rem 0 0 0.375rem !important;
        display: flex;
        align-items: center;
    }
    .select2-container--default .select2-selection--single .select2-selection__arrow { height: 36px !important; }
    .select2-selection__rendered { font-weight: bold !important; color: #102A57 !important; }
</style>

<script>
    $(document).ready(function() {
        $('#select_clientes').select2({
            placeholder: "Escribí el nombre o DNI...",
            allowClear: false
        });
    });
    // --- LECTOR DE TRANSFERENCIAS IA (COPIA DE VENTAS) ---
    let fotosEscanerSorteo = []; 

    function abrirEscanerTransferenciaSorteo(esSuma = false) {
    if(numerosSeleccionados.length === 0){ Swal.fire('Error', 'Elegí al menos un número en la grilla.', 'error'); return; } 
    let idCliente = $('#select_clientes').val();
    if(!idCliente) { Swal.fire('Atención', 'Debés buscar y seleccionar un cliente primero.', 'warning'); return; }

    if (!esSuma) fotosEscanerSorteo = []; 
    
    let input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    input.capture = 'environment';

    input.onchange = e => {
        let file = e.target.files[0];
        if (file) {
            let img = new Image();
            img.onload = () => {
                URL.revokeObjectURL(img.src); 
                let canvas = document.createElement('canvas');
                let ctx = canvas.getContext('2d');
                
                let scale = 1280 / Math.max(img.width, img.height);
                if (scale > 1) scale = 1; 
                canvas.width = img.width * scale;
                canvas.height = img.height * scale;
                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                
                let fotoBase64 = canvas.toDataURL('image/jpeg', 0.80);
                fotosEscanerSorteo.push(fotoBase64);

                Swal.fire({
                    title: fotosEscanerSorteo.length > 1 ? '¡Parte agregada!' : '¿La foto está nítida?',
                    text: `Llevás ${fotosEscanerSorteo.length} captura(s) lista(s)`,
                    imageUrl: fotoBase64,
                    imageWidth: 300,
                    showCancelButton: true,
                    showDenyButton: true,
                    confirmButtonText: '<i class="bi bi-check-lg"></i> Listo, Analizar',
                    denyButtonText: '<i class="bi bi-plus-circle"></i> Sumar otra parte',
                    cancelButtonText: '<i class="bi bi-arrow-repeat"></i> Reintentar',
                    confirmButtonColor: '#28a745',
                    denyButtonColor: '#17a2b8',
                    cancelButtonColor: '#dc3545',
                }).then((result) => {
                    if (result.isConfirmed) {
                        Swal.fire({ title: 'Analizando captura(s)...', html: 'Extrayendo datos con IA<br><i>Esto puede demorar unos segundos</i>', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
                        
                        let precioTotal = numerosSeleccionados.length * precioTicketUnitario;

                        $.ajax({
                            url: 'acciones/procesar_ocr_transferencia.php',
                            type: 'POST',
                            dataType: 'json',
                            data: { imagenes_base64: fotosEscanerSorteo, monto_esperado: precioTotal }, 
                            timeout: 25000, 
                            success: function(res) {
                                if (res.status === 'success' || res.status === 'warning') {
                                    let precioTotal = numerosSeleccionados.length * precioTicketUnitario;
                                    let titulo = res.status === 'success' ? '¡Comprobante Válido!' : '⚠️ ALERTA DE MONTO';
                                    let msjHtml = res.status === 'success' 
                                        ? `La IA aprobó la captura.<br><br>¿Ya impactaron los <b>$${precioTotal.toLocaleString('es-AR')}</b> en tu cuenta bancaria?` 
                                        : `El costo es: <b>$${res.monto_esperado}</b><br>La IA detectó: <b class="text-danger">$${res.monto_leido}</b><br><br>¿Desea forzar la aprobación?`;

                                    Swal.fire({
                                        title: titulo,
                                        html: msjHtml,
                                        icon: res.status === 'success' ? 'success' : 'warning',
                                        showDenyButton: true,
                                        showCancelButton: true,
                                        allowOutsideClick: false,
                                        confirmButtonText: '<i class="bi bi-check-circle"></i> Sí, ya impactó',
                                        denyButtonText: '<i class="bi bi-clock-history"></i> Pendiente',
                                        cancelButtonText: 'Rechazar / Cancelar',
                                        confirmButtonColor: '#198754',
                                        denyButtonColor: '#ffc107',
                                        cancelButtonColor: '#dc3545'
                                    }).then((conf) => {
                                        if (conf.isConfirmed) {
                                            $('#hidden_estado_venta').val('completada');
                                            $('#hidden_id_transferencia').val(res.id_transferencia);
                                            $('#submitReal').click();
                                        } else if (conf.isDenied) {
                                            $('#hidden_estado_venta').val('pendiente_transferencia');
                                            $('#hidden_id_transferencia').val(res.id_transferencia);
                                            $('#submitReal').click();
                                        } else {
                                            $.post('ver_transferencias_ia.php', { solicitud_borrar: 1, ids_a_borrar: JSON.stringify([res.id_transferencia]) });
                                            Swal.fire('Operación pausada', 'Venta rechazada y foto eliminada.', 'info');
                                        }
                                    });
                                } else {
                                    Swal.fire('Error de IA', res.msg || 'No se detectó una transferencia válida.', 'error');
                                }
                            },
                            error: function(jqXHR, textStatus) {
                                if (textStatus === 'timeout') {
                                    Swal.fire('Aviso', 'La IA tardó demasiado en responder. Volvé a intentarlo o cobrá manual.', 'warning');
                                } else {
                                    Swal.fire('Error', 'Se cortó la conexión con el servidor.', 'error');
                                }
                            }
                        });
                    } else if (result.isDenied) {
                        abrirEscanerTransferenciaSorteo(true); 
                    } else {
                        fotosEscanerSorteo.pop(); 
                        abrirEscanerTransferenciaSorteo(true); 
                    }
                });
            };
            img.src = URL.createObjectURL(file); 
        }
    };
    input.click();
}
</script>

<?php include 'includes/layout_footer.php'; ?>