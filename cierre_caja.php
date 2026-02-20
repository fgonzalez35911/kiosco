<?php
// cierre_caja.php - CIERRE UNIVERSAL (Admin puede cerrar cajas de otros)
session_start();
require_once 'includes/db.php';
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$usuario_id = $_SESSION['usuario_id'];
$rol_usuario = $_SESSION['rol'] ?? 3;
$fecha_actual = date('Y-m-d H:i:s');

$id_sesion = null;
$monto_inicial = 0;

// 1. DETERMINAR QUÉ CAJA VAMOS A CERRAR
if (isset($_GET['id_sesion'])) {
    // Si viene por URL (desde historial)
    $id_solicitado = intval($_GET['id_sesion']);
    
    // Verificar permisos: ¿Es mi caja O soy admin?
    $stmtCheck = $conexion->prepare("SELECT id, id_usuario, monto_inicial, estado FROM cajas_sesion WHERE id = ?");
    $stmtCheck->execute([$id_solicitado]);
    $caja = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    
    if ($caja) {
        if ($caja['estado'] == 'cerrada') {
            // Si ya estaba cerrada, redirigir al detalle directamente
            header("Location: ver_detalle_caja.php?id=$id_solicitado"); exit;
        }
        // Permitir si es mi caja O si soy Admin/Dueño
        if ($caja['id_usuario'] == $usuario_id || $rol_usuario <= 2) {
            $id_sesion = $caja['id'];
            $monto_inicial = $caja['monto_inicial'];
        } else {
            die("No tienes permiso para cerrar la caja de otro usuario.");
        }
    }
} 

// Si no se seleccionó ninguna por URL, buscar la MI ACTIVA
if (!$id_sesion) {
    $stmt = $conexion->prepare("SELECT id, monto_inicial FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta'");
    $stmt->execute([$usuario_id]);
    $caja = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if($caja) {
        $id_sesion = $caja['id'];
        $monto_inicial = $caja['monto_inicial'];
    } else {
        // No tengo caja abierta y no pedí cerrar otra -> Ir a apertura
        header("Location: apertura_caja.php"); exit;
    }
}

// 2. OBTENER TOTALES DEL SISTEMA PARA ESA CAJA
// A. Ventas Puras en Efectivo
$sqlVentas = "SELECT SUM(total) FROM ventas WHERE id_caja_sesion = ? AND metodo_pago = 'Efectivo' AND estado = 'completada'";
$stmt = $conexion->prepare($sqlVentas); $stmt->execute([$id_sesion]);
$ventas_efectivo_puro = $stmt->fetchColumn() ?? 0;

// B. Cobros de Deuda en Efectivo
$sqlDeudas = "SELECT SUM(m.monto) FROM movimientos_cc m JOIN ventas v ON m.id_venta = v.id WHERE v.id_caja_sesion = ? AND v.metodo_pago = 'Efectivo' AND m.tipo = 'haber'";
$stmt = $conexion->prepare($sqlDeudas); $stmt->execute([$id_sesion]);
$cobros_deuda_efectivo = $stmt->fetchColumn() ?? 0;

// C. Parte en Efectivo de Pagos Mixtos
$sqlMixtos = "SELECT SUM(monto) FROM pagos_ventas pv JOIN ventas v ON pv.id_venta = v.id WHERE v.id_caja_sesion = ? AND pv.metodo_pago = 'Efectivo'";
$stmt = $conexion->prepare($sqlMixtos); $stmt->execute([$id_sesion]);
$mixtos_efectivo = $stmt->fetchColumn() ?? 0;

// D. GASTOS Y RETIROS
$sqlGastos = "SELECT SUM(monto) FROM gastos WHERE id_caja_sesion = ?";
$stmt = $conexion->prepare($sqlGastos); $stmt->execute([$id_sesion]);
$gastos_totales = $stmt->fetchColumn() ?? 0;

// TOTALES FINALES
$total_entradas = $ventas_efectivo_puro + $cobros_deuda_efectivo + $mixtos_efectivo;
$total_esperado = ($monto_inicial + $total_entradas) - $gastos_totales;

$mensaje = '';
$cierre_exitoso = false;

// PROCESAR CIERRE
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $total_fisico = $_POST['total_declarado']; 
    $diferencia = $total_fisico - $total_esperado;
    
    $sqlCierre = "UPDATE cajas_sesion SET monto_final = ?, total_ventas = ?, diferencia = ?, fecha_cierre = ?, estado = 'cerrada' WHERE id = ?";
    $stmt = $conexion->prepare($sqlCierre);
    $stmt->execute([$total_fisico, $total_entradas, $diferencia, $fecha_actual, $id_sesion]);
    
    // AUDITORÍA: CIERRE DE CAJA
    try {
        $detalles_audit = "Cierre de caja #" . $id_sesion . ". Monto declarado: $" . number_format($total_fisico, 2) . " | Diferencia: $" . number_format($diferencia, 2);
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'CIERRE', ?, NOW())")
                 ->execute([$usuario_id, $detalles_audit]);
    } catch (Exception $e) { }

    $cierre_exitoso = true;
    
    $clase_dif = ($diferencia < 0) ? 'text-danger' : 'text-success';
    $txt_dif = ($diferencia < 0) ? 'FALTANTE' : 'SOBRANTE';
    if(abs($diferencia) < 1) { $clase_dif = 'text-primary'; $txt_dif = 'PERFECTO'; }
    
    // FORMATOS PARA MOSTRAR
    $monto_final_fmt = number_format($total_fisico, 2);
    $diferencia_fmt = number_format($diferencia, 2);
    $esperado_fmt = number_format($total_esperado, 2);
    
    // MENSAJE DE ÉXITO
    $mensaje = "
    <div class='alert alert-light border shadow-sm text-center'>
        <h3 class='fw-bold'>¡Caja #$id_sesion Cerrada!</h3>
        <hr>
        <div class='row'>
            <div class='col-6 text-end text-muted'>Sistema Esperaba:</div>
            <div class='col-6 text-start fw-bold fs-5'>$ {$esperado_fmt}</div>
            
            <div class='col-6 text-end text-muted'>Vos contaste:</div>
            <div class='col-6 text-start fw-bold fs-5'>$ {$monto_final_fmt}</div>
            
            <div class='col-12 mt-3'>
                <h2 class='$clase_dif fw-bold'>$txt_dif: $ {$diferencia_fmt}</h2>
            </div>
        </div>
        <div class='mt-4'>
            <a href='index.php' class='btn btn-outline-secondary'>Volver al Inicio</a>
            <a href='ver_detalle_caja.php?id=$id_sesion' class='btn btn-primary fw-bold'>
                <i class='bi bi-eye'></i> Ver Detalle
            </a>
        </div>
    </div>";
}
?>
<?php require_once 'includes/layout_header.php'; ?>
<style>
    .billete-row { display: flex; align-items: center; margin-bottom: 8px; }
    .billete-label { width: 80px; font-weight: bold; }
    .billete-input { width: 100px; text-align: center; font-weight: bold; }
    .billete-total { margin-left: auto; color: #198754; font-weight: bold; }
    .total-display { background: #212529; color: #0dfd05; font-family: monospace; font-size: 2rem; padding: 10px; border-radius: 8px; text-align: center; }
    .card-header-custom { background: linear-gradient(45deg, #0d6efd, #0dcaf0); color: white; }
</style>

<div class="pb-5">
    <?php if($cierre_exitoso): ?>
        <div class="row justify-content-center mt-5">
            <div class="col-md-6">
                <?php echo $mensaje; ?>
            </div>
        </div>
    <?php else: ?>
    
    <div class="row justify-content-center">
        <div class="col-lg-8">
            <div class="card shadow border-0">
                <div class="card-header card-header-custom fw-bold text-center py-3">
                    <i class="bi bi-calculator"></i> CERRANDO CAJA #<?php echo $id_sesion; ?>
                </div>
                <div class="card-body p-4">
                    <div class="alert alert-info text-center small mb-4">
                        <i class="bi bi-info-circle"></i> Ingresá la cantidad de billetes. El sistema restará los gastos ($<?php echo number_format($gastos_totales,2); ?>).
                    </div>

                    <form method="POST" id="formCierre">
                        <div class="row">
                            <div class="col-md-6 border-end">
                                <h6 class="text-muted mb-3 text-center">Alta Denominación</h6>
                                <div class="billete-row"><div class="billete-label">$ 20.000</div><input type="number" class="form-control billete-input" data-valor="20000" placeholder="0"><div class="billete-total">$ 0</div></div>
                                <div class="billete-row"><div class="billete-label">$ 10.000</div><input type="number" class="form-control billete-input" data-valor="10000" placeholder="0"><div class="billete-total">$ 0</div></div>
                                <div class="billete-row"><div class="billete-label">$ 2.000</div><input type="number" class="form-control billete-input" data-valor="2000" placeholder="0"><div class="billete-total">$ 0</div></div>
                                <div class="billete-row"><div class="billete-label">$ 1.000</div><input type="number" class="form-control billete-input" data-valor="1000" placeholder="0"><div class="billete-total">$ 0</div></div>
                                <div class="billete-row"><div class="billete-label">$ 500</div><input type="number" class="form-control billete-input" data-valor="500" placeholder="0"><div class="billete-total">$ 0</div></div>
                            </div>
                            <div class="col-md-6">
                                <h6 class="text-muted mb-3 text-center">Baja Denominación</h6>
                                <div class="billete-row"><div class="billete-label">$ 200</div><input type="number" class="form-control billete-input" data-valor="200" placeholder="0"><div class="billete-total">$ 0</div></div>
                                <div class="billete-row"><div class="billete-label">$ 100</div><input type="number" class="form-control billete-input" data-valor="100" placeholder="0"><div class="billete-total">$ 0</div></div>
                                <div class="billete-row"><div class="billete-label">$ 50</div><input type="number" class="form-control billete-input" data-valor="50" placeholder="0"><div class="billete-total">$ 0</div></div>
                                <div class="billete-row"><div class="billete-label">$ 20</div><input type="number" class="form-control billete-input" data-valor="20" placeholder="0"><div class="billete-total">$ 0</div></div>
                                <div class="billete-row"><div class="billete-label">$ 10</div><input type="number" class="form-control billete-input" data-valor="10" placeholder="0"><div class="billete-total">$ 0</div></div>
                                <div class="mt-4"><label class="small fw-bold text-muted">Monedas / Suelto ($)</label><input type="number" step="0.01" class="form-control fw-bold text-center" id="inputMonedas" placeholder="0.00"></div>
                            </div>
                        </div>

                        <hr>
                        <div class="text-center p-3 mb-4">
                            <small class="text-uppercase text-muted fw-bold">Total Efectivo en Caja</small>
                            <div class="total-display" id="totalDisplay">$ 0.00</div>
                            <input type="hidden" name="total_declarado" id="inputTotalDeclarado" value="0">
                        </div>

                        <button type="button" id="btn-cerrar-caja" class="btn btn-primary w-100 py-3 fw-bold shadow-sm">
                            <i class="bi bi-lock-fill"></i> CERRAR CAJA DEFINITIVAMENTE
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    const inputs = document.querySelectorAll('.billete-input');
    const inputMonedas = document.getElementById('inputMonedas');
    const display = document.getElementById('totalDisplay');
    const inputHidden = document.getElementById('inputTotalDeclarado');

    function calcularTotal() {
        let total = 0;
        inputs.forEach(i => {
            let cant = parseInt(i.value) || 0;
            let valor = parseInt(i.dataset.valor);
            let subtotal = cant * valor;
            i.parentElement.querySelector('.billete-total').innerText = '$ ' + subtotal.toLocaleString('es-AR');
            total += subtotal;
        });
        let monedas = parseFloat(inputMonedas.value) || 0;
        total += monedas;
        display.innerText = '$ ' + total.toLocaleString('es-AR', {minimumFractionDigits: 2});
        inputHidden.value = total;
    }

    inputs.forEach(input => input.addEventListener('input', calcularTotal));
    inputMonedas.addEventListener('input', calcularTotal);

    $('#btn-cerrar-caja').click(function(e){
        e.preventDefault();
        let total = $('#inputTotalDeclarado').val();
        Swal.fire({
            title: '¿Cerrar Caja #' + <?php echo $id_sesion; ?> + '?',
            text: "Declaras un total de $" + parseFloat(total).toLocaleString('es-AR'),
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#0d6efd',
            confirmButtonText: 'Sí, cerrar caja',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) { $('#formCierre').submit(); }
        })
    });
</script>
<?php require_once 'includes/layout_footer.php'; ?>