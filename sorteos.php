<?php
// sorteos.php - VERSIÓN PRO COMPLETA RESTAURADA
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }
// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);
if (!$es_admin && !in_array('ver_sorteos', $permisos)) { header("Location: dashboard.php"); exit; }
// 1. PROCESAR CREACIÓN DE SORTEO (Estado inicial: pendiente)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_sorteo'])) {
    $titulo = $_POST['titulo'];
    $precio = $_POST['precio'];
    $cant = $_POST['cantidad'];
    $fecha = $_POST['fecha'];
    $descripcion = $_POST['descripcion'] ?? '';
    
    $stmt = $conexion->prepare("INSERT INTO sorteos (titulo, descripcion, precio_ticket, cantidad_tickets, fecha_sorteo, estado) VALUES (?, ?, ?, ?, ?, 'pendiente')");
    $stmt->execute([$titulo, $descripcion, $precio, $cant, $fecha]);
    $idSorteo = $conexion->lastInsertId();
    
    if(isset($_POST['premios_simulados']) && !empty($_POST['premios_simulados'])) {
        $premios = json_decode($_POST['premios_simulados'], true);
        if(is_array($premios)) {
                // Definimos el prepare ANTES de usarlo
                $stmtPremio = $conexion->prepare("INSERT INTO sorteo_premios (id_sorteo, posicion, tipo, id_producto, descripcion_externa, costo_externo) VALUES (?, ?, ?, ?, ?, ?)");
                $pos = 1;
                foreach($premios as $p) {
                    $tipo = ($p['tipo'] === 'manual') ? 'externo' : 'interno';
                    $idProd = ($p['tipo'] !== 'manual') ? $p['id'] : NULL;
                    $desc = ($p['tipo'] === 'manual') ? $p['nombre'] : NULL;
                    $stmtPremio->execute([$idSorteo, $pos, $tipo, $idProd, $desc, $p['costo']]);
                    $pos++;
                }
            }
     }
     $d_aud = "Sorteo Creado: " . $titulo . " | Precio Tkt: $" . $precio; 
    $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'SORTEO_NUEVO', ?, NOW())")->execute([$_SESSION['usuario_id'], $d_aud]);
    header("Location: detalle_sorteo.php?id=$idSorteo&msg=creado"); exit;
}

// 2. FILTROS Y CONSULTAS
$desde = $_GET['desde'] ?? date('Y-01-01');
$hasta = $_GET['hasta'] ?? date('Y-12-31');

$sqlSorteos = "SELECT * FROM sorteos WHERE DATE(fecha_sorteo) >= ? AND DATE(fecha_sorteo) <= ? ORDER BY FIELD(estado, 'pendiente', 'activo', 'finalizado', 'cancelado'), fecha_sorteo DESC";
$stmtS = $conexion->prepare($sqlSorteos);
$stmtS->execute([$desde, $hasta]);
$sorteos = $stmtS->fetchAll(PDO::FETCH_ASSOC);

// Productos para el simulador (Traemos Costo, Público y Código de Barras)
$sqlProds = "SELECT p.id, p.descripcion, p.tipo, p.precio_costo, p.precio_venta, p.precio_oferta, p.codigo_barras,
            (SELECT COALESCE(SUM(prod_hijo.precio_costo * ci.cantidad), 0) FROM combo_items ci JOIN combos c ON c.id = ci.id_combo JOIN productos prod_hijo ON ci.id_producto = prod_hijo.id WHERE c.codigo_barras = p.codigo_barras) as costo_combo_calculado
            FROM productos p WHERE p.activo = 1 ORDER BY p.descripcion ASC";
$rawProds = $conexion->query($sqlProds)->fetchAll(PDO::FETCH_ASSOC);
$prods_simulador = [];
foreach($rawProds as $p) {
    $p['costo_real'] = ($p['tipo'] === 'combo' && $p['costo_combo_calculado'] > 0) ? $p['costo_combo_calculado'] : $p['precio_costo'];
    $p['precio_publico'] = (floatval($p['precio_oferta']) > 0) ? $p['precio_oferta'] : $p['precio_venta'];
    $prods_simulador[] = $p;
}
// KPIs PARA WIDGETS
$total_sorteos = count($sorteos);
$activas = 0; foreach($sorteos as $s) { if($s['estado'] == 'activo') $activas++; }
$total_vendidos = $conexion->query("SELECT COUNT(*) FROM sorteo_tickets")->fetchColumn() ?: 0;
$historial_ganadores = $conexion->query("SELECT id, titulo, ganadores_json FROM sorteos WHERE estado = 'finalizado' ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

$color_sistema = '#102A57';
try { $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1"); if ($resColor) { $dataC = $resColor->fetch(PDO::FETCH_ASSOC); if (isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav']; } } catch (Exception $e) { }

include 'includes/layout_header.php'; 
?>

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden; z-index: 10;">
    <i class="bi bi-ticket-perforated-fill bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Sorteos y Rifas</h2>
                <p class="opacity-75 mb-0 text-white small">Gestión profesional con control de stock y caja.</p>
            </div>
            <div class="d-flex gap-2">
                <?php if($es_admin || in_array('crear_sorteo', $permisos)): ?>
                    <button class="btn btn-light text-danger fw-bold rounded-pill px-4 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalNuevoSorteo">
                        <i class="bi bi-plus-lg me-2"></i> NUEVA RIFA
                    </button>
                <?php endif; ?>
                <a href="reporte_sorteos.php?desde=<?php echo $desde; ?>&hasta=<?php echo $hasta; ?>" target="_blank" class="btn btn-danger fw-bold rounded-pill px-4 shadow">
                    <i class="bi bi-file-earmark-pdf-fill me-2"></i> REPORTE PDF
                </a>
            </div>
        </div>

        <div class="bg-white bg-opacity-10 p-3 rounded-4 shadow-sm d-inline-block border border-white border-opacity-25 mt-2 mb-4">
            <form method="GET" class="d-flex align-items-center gap-3 mb-0">
                <div class="d-flex align-items-center"><span class="small fw-bold text-white text-uppercase me-2">Desde:</span><input type="date" name="desde" class="form-control border-0 shadow-sm rounded-3 fw-bold" value="<?php echo $desde; ?>" required style="max-width: 150px;"></div>
                <div class="d-flex align-items-center"><span class="small fw-bold text-white text-uppercase me-2">Hasta:</span><input type="date" name="hasta" class="form-control border-0 shadow-sm rounded-3 fw-bold" value="<?php echo $hasta; ?>" required style="max-width: 150px;"></div>
                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow"><i class="bi bi-search me-2"></i> FILTRAR</button>
            </form>
        </div>

        <div class="row g-3">
            <div class="col-6 col-md-3"><div class="header-widget"><div><div class="widget-label">Total Campañas</div><div class="widget-value text-white"><?php echo $total_sorteos; ?></div></div><div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-collection"></i></div></div></div>
            <div class="col-6 col-md-3"><div class="header-widget"><div><div class="widget-label">Rifas Activas</div><div class="widget-value text-white"><?php echo $activas; ?></div></div><div class="icon-box bg-success bg-opacity-20 text-white"><i class="bi bi-check-circle"></i></div></div></div>
            <div class="col-6 col-md-3"><div class="header-widget"><div><div class="widget-label">Tickets Vendidos</div><div class="widget-value text-white"><?php echo $total_vendidos; ?></div></div><div class="icon-box bg-warning bg-opacity-20 text-white"><i class="bi bi-ticket-detailed"></i></div></div></div>
            <div class="col-6 col-md-3" style="cursor: pointer;" data-bs-toggle="modal" data-bs-target="#modalGanadores"><div class="header-widget border-info"><div><div class="widget-label">Ganadores</div><div class="widget-value text-white" style="font-size: 1.1rem;">HISTORIAL</div></div><div class="icon-box bg-info bg-opacity-20 text-white"><i class="bi bi-trophy-fill"></i></div></div></div>
        </div>
    </div>
</div>

<div class="container pb-5 mt-4">
    <div class="row g-4">
        <?php foreach($sorteos as $s): 
            $badge = ($s['estado'] == 'activo') ? 'bg-success' : (($s['estado'] == 'pendiente') ? 'bg-warning text-dark' : 'bg-secondary');
            $vendidos = $conexion->query("SELECT COUNT(*) FROM sorteo_tickets WHERE id_sorteo = {$s['id']}")->fetchColumn();
            $progreso = ($s['cantidad_tickets'] > 0) ? ($vendidos / $s['cantidad_tickets']) * 100 : 0;
        ?>
        <div class="col-md-4">
            <div class="card card-sorteo h-100 shadow-sm rounded-4 border-0">
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-3">
                        <span class="badge <?php echo $badge; ?> rounded-pill"><?php echo strtoupper($s['estado']); ?></span>
                        <small class="text-muted"><i class="bi bi-calendar-event"></i> <?php echo date('d/m/Y', strtotime($s['fecha_sorteo'])); ?></small>
                    </div>
                    <h5 class="fw-bold text-dark"><?php echo htmlspecialchars($s['titulo']); ?></h5>
                    <h3 class="text-primary fw-bold">$<?php echo number_format($s['precio_ticket'], 0, ',', '.'); ?> <small class="fs-6 text-muted">/ticket</small></h3>
                    <div class="mt-3">
                        <div class="d-flex justify-content-between small text-muted mb-1"><span>Vendidos: <?php echo $vendidos; ?></span><span>Total: <?php echo $s['cantidad_tickets']; ?></span></div>
                        <div class="progress" style="height: 8px;"><div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $progreso; ?>%"></div></div>
                    </div>
                </div>
                <div class="card-footer bg-white border-0 pt-0 pb-3"><a href="detalle_sorteo.php?id=<?php echo $s['id']; ?>" class="btn btn-outline-primary w-100 rounded-pill fw-bold">ADMINISTRAR</a></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="modalGanadores" tabindex="-1">
    <div class="modal-dialog modal-lg"><div class="modal-content rounded-4 border-0">
        <div class="modal-header bg-dark text-white border-0 py-3"><h5 class="modal-title fw-bold">Últimos Ganadores</h5><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>
        <div class="modal-body p-0"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead class="bg-light small uppercase"><tr><th class="ps-4">Sorteo</th><th>Ganador</th><th>Premio</th><th class="pe-4 text-end">WA</th></tr></thead>
            <tbody><?php foreach($historial_ganadores as $hg): $ganadores = json_decode($hg['ganadores_json'], true); if(is_array($ganadores)): foreach($ganadores as $g): 
                $num_wa = preg_replace('/[^0-9]/', '', $g['telefono'] ?? '');
            ?>
                <tr><td class="ps-4"><small class="fw-bold"><?php echo $hg['titulo']; ?></small></td><td><div class="fw-bold"><?php echo $g['cliente']; ?></div><small class="text-muted">Ticket #<?php echo $g['ticket']; ?></small></td><td><span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25"><?php echo $g['premio']; ?></span></td><td class="pe-4 text-end"><?php if(!empty($num_wa)): ?><a href="https://wa.me/<?php echo $num_wa; ?>?text=<?php echo urlencode("¡Hola ".$g['cliente']."! 🏆 Sos el ganador del sorteo ".$hg['titulo'].". Ganaste: ".$g['premio'].". Te esperamos en la tienda 🎉"); ?>" target="_blank" class="btn btn-sm btn-success rounded-circle shadow-sm"><i class="bi bi-whatsapp"></i></a><?php else: ?>-<?php endif; ?></td></tr>
            <?php endforeach; endif; endforeach; ?></tbody>
        </table></div></div>
    </div></div>
</div>

<div class="modal fade" id="modalNuevoSorteo" tabindex="-1">
    <div class="modal-dialog modal-xl"><form method="POST" class="modal-content rounded-4 border-0" id="formCrearSorteo">
        <input type="hidden" name="premios_simulados" id="input_premios_simulados">
        <div class="modal-header border-0 bg-light"><h5 class="modal-title fw-bold">Nueva Rifa</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body"><div class="row">
            <div class="col-md-4 border-end">
                <h6 class="text-muted fw-bold mb-3 small uppercase">1. Configuración</h6>
                <div class="mb-3"><label class="form-label fw-bold">Título</label><input type="text" name="titulo" class="form-control" required></div>
                <div class="mb-3"><label class="form-label fw-bold">Descripción</label><textarea name="descripcion" class="form-control" rows="2"></textarea></div>
                <div class="row">
                    <div class="col-6 mb-3"><label class="form-label fw-bold">Precio Ticket</label><input type="number" name="precio" id="sim_precio" class="form-control" required step="0.01" oninput="calcularSimulacion()"></div>
                    <div class="col-6 mb-3"><label class="form-label fw-bold">Cant. Tickets</label><input type="number" name="cantidad" id="sim_cantidad" class="form-control" value="100" required oninput="calcularSimulacion()"></div>
                </div>
                <div class="mb-3"><label class="form-label fw-bold">Fecha Sorteo</label><input type="date" name="fecha" class="form-control" required></div>
            </div>
            <div class="col-md-8">
                <div class="d-flex justify-content-between align-items-center mb-2"><h6 class="text-primary fw-bold m-0 small uppercase"><i class="bi bi-gift"></i> Premios y Simulador</h6><button type="button" class="btn btn-sm btn-outline-success fw-bold" onclick="agregarFilaPremio()"><i class="bi bi-plus-lg"></i> Agregar</button></div>
                <div class="simulador-box"><div id="contenedor_premios"></div></div>
                <div id="resultado_sim" class="resultado-simulacion bg-light text-muted">Defina precio y premios...</div>
                <div class="mt-3 small row">
                    <div class="col-6 text-center border-end"><span class="d-block text-muted">Ingreso Bruto</span><span class="fs-5 fw-bold text-dark" id="txt_recaudacion">$0</span></div>
                    <div class="col-6 text-center"><span class="d-block text-muted">Costo Stock</span><span class="fs-5 fw-bold text-danger" id="txt_costos">$0</span></div>
                </div>
            </div>
        </div></div>
        <div class="modal-footer border-0"><button type="submit" name="crear_sorteo" class="btn btn-primary w-100 rounded-pill fw-bold" onclick="prepararEnvio()">GUARDAR COMO PENDIENTE</button></div>
    </form></div>
</div>

<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
const productosSimulador = <?php echo json_encode($prods_simulador); ?>;
let contadorPremios = 0;
// Motor oculto para buscar por código de barras sin mostrarlo en el texto
function matchPorCodigoOTexto(params, data) {
    if ($.trim(params.term) === '') return data;
    if (typeof data.text === 'undefined') return null;
    let busqueda = params.term.toLowerCase();
    let textoVisible = data.text.toLowerCase();
    let codigoBarra = data.element ? (data.element.getAttribute('data-codigo') || '').toLowerCase() : '';
    
    // Busca coincidencias en el nombre o en el código de barras oculto
    if (textoVisible.indexOf(busqueda) > -1 || codigoBarra.indexOf(busqueda) > -1) {
        return data;
    }
    return null;
}

function agregarFilaPremio() {
    contadorPremios++;
    let optionsHtml = '<option value="0" data-costo="0" data-codigo="">Buscar o escanear...</option>';
    productosSimulador.forEach(p => { 
        let costo = parseFloat(p.costo_real).toLocaleString('es-AR');
        let publico = parseFloat(p.precio_publico).toLocaleString('es-AR');
        let cb = p.codigo_barras || ''; // Guardamos el código de barras de forma invisible
        
        // ELIMINAMOS EL CÓDIGO DE BARRAS DEL TEXTO VISIBLE
        optionsHtml += `<option value="${p.id}" data-costo="${p.costo_real}" data-codigo="${cb}" data-nombre="${p.descripcion}">${(p.tipo === 'combo') ? '[PACK] ' : ''}${p.descripcion} (Público: $${publico} | Costo: $${costo})</option>`; 
    });
    
    const html = `<div class="fila-premio d-flex gap-2 align-items-center mb-2" id="fila_premio_${contadorPremios}">
        <div class="fw-bold text-secondary" style="width:25px;">#${contadorPremios}</div>
        <div style="width:100px;">
            <select class="form-select form-select-sm tipo-premio" onchange="cambiarTipoPremio(${contadorPremios}, this)">
                <option value="interno">Stock</option>
                <option value="manual">Manual</option>
            </select>
        </div>
        <div class="flex-grow-1" id="div_prod_${contadorPremios}" style="min-width: 200px;">
            <select class="form-select form-select-sm select-prod" id="select_prod_${contadorPremios}" onchange="actualizarCosto(${contadorPremios}, this)">
                ${optionsHtml}
            </select>
        </div>
        <div class="flex-grow-1 d-none" id="div_manual_${contadorPremios}">
            <input type="text" class="form-control form-control-sm input-manual" placeholder="Escriba el premio manual...">
        </div>
        <div class="input-group input-group-sm" style="width:160px;">
            <span class="input-group-text bg-light border-end-0 fw-bold" title="Costo Real">$</span>
            <input type="number" step="0.01" class="form-control costo-item border-start-0 text-dark fw-bold" id="costo_${contadorPremios}" value="0" oninput="calcularSimulacion()" readonly>
        </div>
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarFila(${contadorPremios})"><i class="bi bi-trash"></i></button>
    </div>`;
    
    document.getElementById('contenedor_premios').insertAdjacentHTML('beforeend', html);
    
    // Inicializamos el buscador con nuestro "motor" oculto
    $(`#select_prod_${contadorPremios}`).select2({
        theme: 'bootstrap-5',
        dropdownParent: $('#modalNuevoSorteo'),
        width: '100%',
        placeholder: "Escribe o escanea...",
        matcher: matchPorCodigoOTexto
    });
    
    calcularSimulacion();
}
function cambiarTipoPremio(id, select) {
    const divProd = document.getElementById(`div_prod_${id}`); const divManual = document.getElementById(`div_manual_${id}`); const inputCosto = document.getElementById(`costo_${id}`);
    if(select.value === 'manual') { divProd.classList.add('d-none'); divManual.classList.remove('d-none'); inputCosto.readOnly = false; inputCosto.value = 0; } 
    else { divProd.classList.remove('d-none'); divManual.classList.add('d-none'); inputCosto.readOnly = true; inputCosto.value = 0; }
    calcularSimulacion();
}
function actualizarCosto(id, select) { document.getElementById(`costo_${id}`).value = select.options[select.selectedIndex].getAttribute('data-costo'); calcularSimulacion(); }
function eliminarFila(id) { document.getElementById(`fila_premio_${id}`).remove(); calcularSimulacion(); }
function calcularSimulacion() {
    let precio = parseFloat(document.getElementById('sim_precio').value) || 0; let cantidad = parseInt(document.getElementById('sim_cantidad').value) || 0;
    let recaudacion = precio * cantidad; let costoTotal = 0;
    document.querySelectorAll('.costo-item').forEach(el => { costoTotal += parseFloat(el.value) || 0; });
    let ganancia = recaudacion - costoTotal;
    document.getElementById('txt_recaudacion').innerText = '$' + recaudacion.toLocaleString();
    document.getElementById('txt_costos').innerText = '$' + costoTotal.toLocaleString();
    let divRes = document.getElementById('resultado_sim');
    divRes.className = 'resultado-simulacion ' + (ganancia >= 0 ? 'ganancia' : 'perdida');
    divRes.innerHTML = (ganancia >= 0 ? 'Ganancia' : 'Pérdida') + ` Estimada: $${ganancia.toLocaleString()}`;
}
function prepararEnvio() {
    let premios = [];
    document.querySelectorAll('[id^="fila_premio_"]').forEach(row => {
        let tipo = row.querySelector('.tipo-premio').value; let obj = { tipo: tipo, costo: row.querySelector('.costo-item').value };
        if(tipo === 'interno') { let sel = row.querySelector('.select-prod'); obj.id = sel.value; obj.nombre = sel.options[sel.selectedIndex].text; } 
        else { obj.nombre = row.querySelector('.input-manual').value; }
        premios.push(obj);
    });
    document.getElementById('input_premios_simulados').value = JSON.stringify(premios);
}
document.addEventListener("DOMContentLoaded", () => { if(contadorPremios === 0) agregarFilaPremio(); });
</script>
<style>.simulador-box { max-height: 250px; overflow-y: auto; padding: 10px; border: 1px solid #eee; border-radius: 10px; margin-bottom: 10px; }.resultado-simulacion { padding: 10px; border-radius: 8px; text-align: center; font-weight: bold; }.ganancia { background: #d1e7dd; color: #0f5132; }.perdida { background: #f8d7da; color: #842029; }</style>
<?php include 'includes/layout_footer.php'; ?>