<?php
session_start();
require_once 'includes/db.php';

// Seguridad
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// Procesar Creación de Sorteo
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_sorteo'])) {
    $titulo = $_POST['titulo'];
    $precio = $_POST['precio'];
    $cant = $_POST['cantidad'];
    $fecha = $_POST['fecha'];
    $descripcion = $_POST['descripcion'] ?? ''; // Agregado campo descripción
    
    // CREAMOS COMO ACTIVO DIRECTAMENTE (Para respetar tu ENUM de base de datos)
    // La edición se bloqueará solo si hay ventas, no por el estado.
    $stmt = $conexion->prepare("INSERT INTO sorteos (titulo, descripcion, precio_ticket, cantidad_tickets, fecha_sorteo, estado) VALUES (?, ?, ?, ?, ?, 'activo')");
    $stmt->execute([$titulo, $descripcion, $precio, $cant, $fecha]);
    $idSorteo = $conexion->lastInsertId();
    
    // Guardar premios del simulador si existen
    if(isset($_POST['premios_simulados']) && !empty($_POST['premios_simulados'])) {
        $premios = json_decode($_POST['premios_simulados'], true);
        if(is_array($premios)) {
            $stmtPremio = $conexion->prepare("INSERT INTO sorteo_premios (id_sorteo, posicion, tipo, id_producto, descripcion_externa) VALUES (?, ?, ?, ?, ?)");
            $pos = 1;
            foreach($premios as $p) {
                $tipo = ($p['tipo'] === 'manual') ? 'externo' : 'interno';
                $idProd = ($p['tipo'] !== 'manual') ? $p['id'] : NULL;
                $desc = ($p['tipo'] === 'manual') ? $p['nombre'] : NULL; // Si es manual, el nombre es la descripción
                $stmtPremio->execute([$idSorteo, $pos, $tipo, $idProd, $desc]);
                $pos++;
            }
        }
    }

    header("Location: detalle_sorteo.php?id=$idSorteo&msg=creado");
    exit;
}

// Listar Sorteos
$sorteos = $conexion->query("SELECT * FROM sorteos ORDER BY FIELD(estado, 'activo', 'finalizado', 'cancelado'), fecha_sorteo DESC")->fetchAll(PDO::FETCH_ASSOC);

// --- CONSULTA SQL CORREGIDA (EL PUENTE DE COSTOS) ---
// Esta consulta conecta Productos con Combos via Codigo de Barras para sumar los ingredientes
$sqlProds = "
    SELECT 
        p.id, 
        p.descripcion, 
        p.tipo,
        p.precio_costo as costo_individual,
        p.codigo_barras,
        (
            SELECT COALESCE(SUM(prod_hijo.precio_costo * ci.cantidad), 0)
            FROM combo_items ci 
            JOIN combos c ON c.id = ci.id_combo 
            JOIN productos prod_hijo ON ci.id_producto = prod_hijo.id 
            WHERE c.codigo_barras = p.codigo_barras
        ) as costo_combo_calculado
    FROM productos p 
    WHERE p.activo = 1 
    ORDER BY p.descripcion ASC
";
$rawProds = $conexion->query($sqlProds)->fetchAll(PDO::FETCH_ASSOC);

$prods_simulador = [];
// --- NUEVOS CONTADORES PARA WIDGETS ---
$total_sorteos = count($sorteos);
$activas = 0;
foreach($sorteos as $s) { if($s['estado'] == 'activo') $activas++; }
$total_vendidos = $conexion->query("SELECT COUNT(*) FROM sorteo_tickets")->fetchColumn() ?: 0;
foreach($rawProds as $p) {
    // Si es combo y el cálculo dio mayor a 0, usamos el costo calculado. Si no, el costo directo del producto.
    if ($p['tipo'] === 'combo' && $p['costo_combo_calculado'] > 0) {
        $p['costo_real'] = $p['costo_combo_calculado'];
    } else {
        $p['costo_real'] = $p['costo_individual'];
    }
    $prods_simulador[] = $p;
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
    <i class="bi bi-ticket-perforated-fill bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Sorteos y Rifas</h2>
                <p class="opacity-75 mb-0 text-white small">Gestioná la suerte del negocio con control de stock y caja.</p>
            </div>
            <button class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow" data-bs-toggle="modal" data-bs-target="#modalNuevoSorteo">
                <i class="bi bi-plus-lg me-2"></i> NUEVA RIFA
            </button>
        </div>

        <div class="row g-3">
            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Total Campañas</div>
                        <div class="widget-value text-white"><?php echo $total_sorteos; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white">
                        <i class="bi bi-collection"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Rifas Activas</div>
                        <div class="widget-value text-white"><?php echo $activas; ?></div>
                    </div>
                    <div class="icon-box bg-success bg-opacity-20 text-white">
                        <i class="bi bi-check-circle"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Tickets Vendidos</div>
                        <div class="widget-value text-white"><?php echo $total_vendidos; ?></div>
                    </div>
                    <div class="icon-box bg-warning bg-opacity-20 text-white">
                        <i class="bi bi-ticket-detailed"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget border-info">
                    <div>
                        <div class="widget-label">Estado Módulo</div>
                        <div class="widget-value text-white" style="font-size: 1.1rem;">OPERATIVO</div>
                    </div>
                    <div class="icon-box bg-info bg-opacity-20 text-white">
                        <i class="bi bi-gear"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <div class="row g-4">
        <?php foreach($sorteos as $s): 
            $badge = ($s['estado'] == 'activo') ? 'bg-success' : 'bg-secondary';
            $vendidos = $conexion->query("SELECT COUNT(*) FROM sorteo_tickets WHERE id_sorteo = {$s['id']}")->fetchColumn();
            $progreso = ($s['cantidad_tickets'] > 0) ? ($vendidos / $s['cantidad_tickets']) * 100 : 0;
        ?>
        <div class="col-md-4">
            <div class="card card-sorteo h-100 shadow-sm rounded-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-3">
                        <span class="badge <?php echo $badge; ?> rounded-pill"><?php echo strtoupper($s['estado']); ?></span>
                        <small class="text-muted"><i class="bi bi-calendar-event"></i> <?php echo date('d/m/Y', strtotime($s['fecha_sorteo'])); ?></small>
                    </div>
                    <h5 class="fw-bold text-dark"><?php echo htmlspecialchars($s['titulo']); ?></h5>
                    <h3 class="text-primary fw-bold">$<?php echo number_format($s['precio_ticket'], 0, ',', '.'); ?> <small class="fs-6 text-muted">/ticket</small></h3>
                    
                    <div class="mt-3">
                        <div class="d-flex justify-content-between small text-muted mb-1">
                            <span>Vendidos: <?php echo $vendidos; ?></span>
                            <span>Total: <?php echo $s['cantidad_tickets']; ?></span>
                        </div>
                        <div class="progress" style="height: 8px;">
                            <div class="progress-bar bg-primary" role="progressbar" style="width: <?php echo $progreso; ?>%"></div>
                        </div>
                    </div>
                </div>
                <div class="card-footer bg-white border-0 pt-0 pb-3">
                    <a href="detalle_sorteo.php?id=<?php echo $s['id']; ?>" class="btn btn-outline-primary w-100 rounded-pill fw-bold">
                        ADMINISTRAR
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="modal fade" id="modalNuevoSorteo" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <form method="POST" class="modal-content rounded-4 border-0" id="formCrearSorteo">
            <input type="hidden" name="premios_simulados" id="input_premios_simulados">
            
            <div class="modal-header border-0 bg-light">
                <h5 class="modal-title fw-bold">Nueva Rifa</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-4 border-end">
                        <h6 class="text-muted fw-bold mb-3">1. Datos del Sorteo</h6>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Título</label>
                            <input type="text" name="titulo" class="form-control" required placeholder="Ej: Rifa Semana Santa">
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Descripción (Opcional)</label>
                            <textarea name="descripcion" class="form-control" rows="2"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label fw-bold">Precio Ticket</label>
                                <input type="number" name="precio" id="sim_precio" class="form-control" required step="0.01" oninput="calcularSimulacion()">
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label fw-bold">Cant. Tickets</label>
                                <input type="number" name="cantidad" id="sim_cantidad" class="form-control" value="100" required oninput="calcularSimulacion()">
                            </div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Fecha Sorteo</label>
                            <input type="date" name="fecha" class="form-control" required>
                        </div>
                    </div>

                    <div class="col-md-8">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="text-primary fw-bold m-0"><i class="bi bi-gift"></i> Premios y Simulador de Costos</h6>
                            <button type="button" class="btn btn-sm btn-outline-success fw-bold" onclick="agregarFilaPremio()">
                                <i class="bi bi-plus-lg"></i> Agregar Premio
                            </button>
                        </div>
                        <small class="d-block text-muted mb-2">Agrega aquí los premios. Se guardarán automáticamente al crear la rifa.</small>
                        
                        <div class="simulador-box">
                            <div id="contenedor_premios"></div>
                        </div>

                        <div id="resultado_sim" class="resultado-simulacion bg-light text-muted">
                            Define precio y premios...
                        </div>
                        
                        <div class="mt-3 small row">
                            <div class="col-6 text-center border-end">
                                <span class="d-block text-muted">Ingreso Total Potencial</span>
                                <span class="fs-5 fw-bold text-dark" id="txt_recaudacion">$0</span>
                            </div>
                            <div class="col-6 text-center">
                                <span class="d-block text-muted">Costo Total Premios</span>
                                <span class="fs-5 fw-bold text-danger" id="txt_costos">$0</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-0">
                <button type="submit" name="crear_sorteo" class="btn btn-primary w-100 rounded-pill fw-bold" onclick="prepararEnvio()">CREAR RIFA</button>
            </div>
        </form>
    </div>
</div>

<script>
const productosSimulador = <?php echo json_encode($prods_simulador); ?>;
let contadorPremios = 0;

function agregarFilaPremio() {
    contadorPremios++;
    let optionsHtml = '<option value="0" data-costo="0">Seleccionar Producto/Pack...</option>';
    
    productosSimulador.forEach(p => {
        let etiqueta = (p.tipo === 'combo') ? '[PACK] ' : '';
        optionsHtml += `<option value="${p.id}" data-costo="${p.costo_real}" data-nombre="${p.descripcion}">${etiqueta}${p.descripcion} (Costo: $${parseFloat(p.costo_real).toLocaleString()})</option>`;
    });

    const html = `
    <div class="fila-premio d-flex gap-2 align-items-center flex-wrap" id="fila_premio_${contadorPremios}">
        <div class="fw-bold text-secondary" style="width: 25px;">#${contadorPremios}</div>
        
        <div style="width: 140px;">
            <select class="form-select form-select-sm tipo-premio" onchange="cambiarTipoPremio(${contadorPremios}, this)">
                <option value="interno">Producto Local</option>
                <option value="manual">🎁 Externo</option>
            </select>
        </div>

        <div class="flex-grow-1" id="div_prod_${contadorPremios}">
            <select class="form-select form-select-sm select-prod" onchange="actualizarCosto(${contadorPremios}, this)">
                ${optionsHtml}
            </select>
        </div>

        <div class="flex-grow-1 d-none" id="div_manual_${contadorPremios}">
            <input type="text" class="form-control form-control-sm input-manual" placeholder="Ej: Viaje a Bariloche">
        </div>

        <div class="input-group input-group-sm" style="width: 120px;">
            <span class="input-group-text">$</span>
            <input type="number" class="form-control costo-item" id="costo_${contadorPremios}" value="0" oninput="calcularSimulacion()" readonly>
        </div>
        
        <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarFila(${contadorPremios})"><i class="bi bi-trash"></i></button>
    </div>
    `;
    document.getElementById('contenedor_premios').insertAdjacentHTML('beforeend', html);
    calcularSimulacion();
}

function cambiarTipoPremio(id, select) {
    const divProd = document.getElementById(`div_prod_${id}`);
    const divManual = document.getElementById(`div_manual_${id}`);
    const inputCosto = document.getElementById(`costo_${id}`);

    if(select.value === 'manual') {
        divProd.classList.add('d-none');
        divManual.classList.remove('d-none');
        inputCosto.readOnly = false;
        inputCosto.value = 0;
        inputCosto.focus();
    } else {
        divProd.classList.remove('d-none');
        divManual.classList.add('d-none');
        inputCosto.readOnly = true;
        inputCosto.value = 0;
    }
    calcularSimulacion();
}

function actualizarCosto(id, select) {
    const costo = select.options[select.selectedIndex].getAttribute('data-costo');
    document.getElementById(`costo_${id}`).value = costo;
    calcularSimulacion();
}

function eliminarFila(id) {
    document.getElementById(`fila_premio_${id}`).remove();
    calcularSimulacion();
}

function calcularSimulacion() {
    let precio = parseFloat(document.getElementById('sim_precio').value) || 0;
    let cantidad = parseInt(document.getElementById('sim_cantidad').value) || 0;
    let recaudacion = precio * cantidad;
    
    let costoTotal = 0;
    document.querySelectorAll('.costo-item').forEach(el => { costoTotal += parseFloat(el.value) || 0; });
    
    let ganancia = recaudacion - costoTotal;
    
    document.getElementById('txt_recaudacion').innerText = '$' + recaudacion.toLocaleString();
    document.getElementById('txt_costos').innerText = '$' + costoTotal.toLocaleString();
    
    let divRes = document.getElementById('resultado_sim');
    if(ganancia >= 0) {
        divRes.className = 'resultado-simulacion ganancia';
        divRes.innerHTML = `Ganancia Estimada: $${ganancia.toLocaleString()}`;
    } else {
        divRes.className = 'resultado-simulacion perdida';
        divRes.innerHTML = `Pérdida Estimada: $${ganancia.toLocaleString()}`;
    }
}

function prepararEnvio() {
    let premios = [];
    document.querySelectorAll('[id^="fila_premio_"]').forEach(row => {
        let tipo = row.querySelector('.tipo-premio').value;
        let obj = { tipo: tipo, costo: row.querySelector('.costo-item').value };
        
        if(tipo === 'interno') {
            let sel = row.querySelector('.select-prod');
            obj.id = sel.value;
            obj.nombre = sel.options[sel.selectedIndex].text;
        } else {
            obj.nombre = row.querySelector('.input-manual').value;
        }
        premios.push(obj);
    });
    document.getElementById('input_premios_simulados').value = JSON.stringify(premios);
}

document.addEventListener("DOMContentLoaded", () => { agregarFilaPremio(); });
</script>

<?php include 'includes/layout_footer.php'; ?>