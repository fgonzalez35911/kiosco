<?php
// cierre_caja.php - DISEÑO TECH + ÉXITO PROFESIONAL (Lógica Intacta)
session_start();
require_once 'includes/db.php'; 
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$usuario_id = $_SESSION['usuario_id'];
$rol_usuario = $_SESSION['rol'] ?? 3;
$fecha_actual = date('Y-m-d H:i:s');

// --- CANDADO: PERMISO PARA ARQUEO ---
$permisos_caja = $_SESSION['permisos'] ?? [];
if (($rol_usuario > 2) && !in_array('caja_cerrar_turno', $permisos_caja)) {
    die("<div style='padding:50px; text-align:center; font-family:sans-serif; background:#0f172a; color:#fff; height:100vh;'><h2>⛔ ACCESO DENEGADO</h2><p>No tienes el candado para realizar el Cierre de Caja.</p><a href='dashboard.php' style='color:#22c55e;'>Volver al Inicio</a></div>");
}

$id_sesion = null;
$monto_inicial = 0;

// 1. DETERMINAR CAJA (Lógica original)
if (isset($_GET['id_sesion'])) {
    $id_solicitado = intval($_GET['id_sesion']);
    $stmtCheck = $conexion->prepare("SELECT id, id_usuario, monto_inicial, estado FROM cajas_sesion WHERE id = ?");
    $stmtCheck->execute([$id_solicitado]);
    $caja = $stmtCheck->fetch(PDO::FETCH_ASSOC);
    if ($caja) {
        if ($caja['estado'] == 'cerrada') { header("Location: ver_detalle_caja.php?id=$id_solicitado"); exit; }
        if ($caja['id_usuario'] == $usuario_id || $rol_usuario <= 2) {
            $id_sesion = $caja['id']; $monto_inicial = $caja['monto_inicial'];
        } else { die("Sin permiso."); }
    }
} 
if (!$id_sesion) {
    $stmt = $conexion->prepare("SELECT id, monto_inicial FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta'");
    $stmt->execute([$usuario_id]); $caja = $stmt->fetch(PDO::FETCH_ASSOC);
    if($caja) { $id_sesion = $caja['id']; $monto_inicial = $caja['monto_inicial']; } 
    else { header("Location: apertura_caja.php"); exit; }
}

// 2. CÁLCULOS (CAJA NEGRA)
$stmt = $conexion->prepare("SELECT SUM(total) FROM ventas WHERE id_caja_sesion = ? AND metodo_pago = 'Efectivo' AND estado = 'completada'");
$stmt->execute([$id_sesion]); $v_ef = $stmt->fetchColumn() ?? 0;
$stmt = $conexion->prepare("SELECT SUM(m.monto) FROM movimientos_cc m JOIN ventas v ON m.id_venta = v.id WHERE v.id_caja_sesion = ? AND v.metodo_pago = 'Efectivo' AND m.tipo = 'haber'");
$stmt->execute([$id_sesion]); $d_ef = $stmt->fetchColumn() ?? 0;
$stmt = $conexion->prepare("SELECT SUM(monto) FROM pagos_ventas pv JOIN ventas v ON pv.id_venta = v.id WHERE v.id_caja_sesion = ? AND pv.metodo_pago = 'Efectivo'");
$stmt->execute([$id_sesion]); $m_ef = $stmt->fetchColumn() ?? 0;
$stmt = $conexion->prepare("SELECT SUM(monto) FROM gastos WHERE id_caja_sesion = ?");
$stmt->execute([$id_sesion]); $g = $stmt->fetchColumn() ?? 0;

$total_entradas = $v_ef + $d_ef + $m_ef;
$total_esperado = ($monto_inicial + $total_entradas) - $g;

$cierre_exitoso = false;
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $total_fisico = $_POST['total_declarado']; 
    $diferencia = $total_fisico - $total_esperado;
    $stmt = $conexion->prepare("UPDATE cajas_sesion SET monto_final = ?, total_ventas = ?, diferencia = ?, fecha_cierre = ?, estado = 'cerrada' WHERE id = ?");
    $stmt->execute([$total_fisico, $total_entradas, $diferencia, $fecha_actual, $id_sesion]);
    $cierre_exitoso = true;
}

require_once 'includes/layout_header.php'; ?>

<style>
    /* Recuperamos el estilo TECH/DARK que te gustó */
    body { background: #0f172a; color: #f8fafc; font-family: 'Inter', sans-serif; }
    .main-wrapper { min-height: 100vh; display: flex; flex-direction: column; padding: 20px; }

    .tech-header {
        background: #1e293b; border: 1px solid #334155; border-radius: 20px;
        padding: 20px 30px; display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.4);
    }
    .total-val { color: #22c55e; font-family: monospace; font-size: 3.5rem; font-weight: 900; }

    /* Grilla 4x3 en PC */
    .tech-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; }

    .billete-box {
        background: #1e293b; border: 1px solid #334155; border-radius: 15px;
        padding: 15px; display: flex; flex-direction: column; justify-content: center;
    }
    .b-label { font-size: 1.3rem; font-weight: 900; color: #fff; }
    .b-subtotal { color: #4ade80; font-weight: bold; font-size: 0.9rem; }

    .qty-control { display: flex; align-items: center; background: #0f172a; border-radius: 10px; padding: 4px; border: 1px solid #334155; }
    .btn-qty { width: 45px; height: 45px; border: none; background: #334155; color: #fff; font-size: 1.5rem; border-radius: 8px; cursor: pointer; }
    .btn-qty:active { background: #3b82f6; transform: scale(0.9); }
    
    .b-input { flex-grow: 1; background: transparent; border: none; color: #fff; text-align: center; font-size: 1.8rem; font-weight: bold; width: 40px; }
    .b-input::-webkit-inner-spin-button { -webkit-appearance: none; }

    /* Widget Celular Inferior */
    .widget-mobile-bottom {
        display: none; position: fixed; bottom: 0; left: 0; right: 0;
        background: #22c55e; color: #0f172a; padding: 15px 20px;
        z-index: 2000; box-shadow: 0 -5px 20px rgba(0,0,0,0.4);
        justify-content: space-between; align-items: center;
    }

    /* Pantalla de Éxito INTEGRADA */
    .success-card { background: #fff; color: #334155; border-radius: 20px; overflow: hidden; }
    .success-header { background: #f8fafc; border-bottom: 1px solid #e2e8f0; padding: 20px; }

    /* Mensaje de Alerta Estilizado */
    .alert-cierre {
        background: rgba(239, 68, 68, 0.1); border: 1px solid #ef4444; color: #f87171;
        border-radius: 15px; padding: 15px; margin-bottom: 15px; display: flex; align-items: center;
    }

    @media (max-width: 992px) {
        .tech-grid { grid-template-columns: repeat(2, 1fr); gap: 8px; }
        .tech-header { display: none; }
        .widget-mobile-bottom { display: flex; }
        .main-wrapper { padding: 10px; padding-bottom: 100px; }
    }
</style>

<div class="main-wrapper">
    <?php if($cierre_exitoso): 
        $color = ($diferencia < 0) ? 'text-danger' : 'text-success';
        $txt = ($diferencia < 0) ? 'FALTANTE' : 'SOBRANTE';
        if(abs($diferencia) < 1) { $color = 'text-primary'; $txt = 'PERFECTO'; }
    ?>
        <div class="row justify-content-center m-auto w-100">
            <div class="col-md-6 col-lg-5">
                <div class="success-card shadow-lg border-0 animate__animated animate__fadeInUp">
                    <div class="success-header text-center">
                        <h4 class="mb-0 fw-bold">Resumen de Cierre #<?php echo $id_sesion; ?></h4>
                    </div>
                    <div class="card-body p-4">
                        <table class="table table-borderless mb-4">
                            <tr class="border-bottom">
                                <td class="py-2 text-muted">Sistema esperaba:</td>
                                <td class="py-2 text-end fw-bold">$ <?php echo number_format($total_esperado, 2, ',', '.'); ?></td>
                            </tr>
                            <tr class="border-bottom">
                                <td class="py-2 text-muted">Vos contaste:</td>
                                <td class="py-2 text-end fw-bold">$ <?php echo number_format($total_fisico, 2, ',', '.'); ?></td>
                            </tr>
                            <tr>
                                <td class="py-3 h4 fw-bold">Diferencia:</td>
                                <td class="py-3 text-end h4 fw-bold <?php echo $color; ?>">$ <?php echo number_format($diferencia, 2, ',', '.'); ?></td>
                            </tr>
                        </table>
                        
                        <div class="text-center p-2 rounded-3 mb-4 <?php echo ($diferencia < 0) ? 'bg-danger' : 'bg-success'; ?> bg-opacity-10">
                            <span class="fw-bold <?php echo $color; ?>">ESTADO: <?php echo $txt; ?></span>
                        </div>

                        <div class="d-grid gap-2">
                            <a href="ver_detalle_caja.php?id=<?php echo $id_sesion; ?>" class="btn btn-primary btn-lg fw-bold">Ver Detalles de Caja</a>
                            <a href="index.php" class="btn btn-outline-secondary">Volver al Inicio</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    <?php else: ?>

    <div class="tech-header">
        <div>
            <span class="text-white-50 small fw-bold text-uppercase">Arqueo en Vivo</span>
            <div class="total-val" id="totalDisplay">$ 0,00</div>
        </div>
        <button type="button" onclick="confirmarCierre()" class="btn btn-success btn-lg fw-bold rounded-pill px-5">CERRAR CAJA</button>
    </div>

    <div class="widget-mobile-bottom" id="mobileWidget">
        <div style="font-size: 1.5rem; font-weight: 900;" id="totalWidget">$ 0,00</div>
        <button onclick="confirmarCierre()" class="btn btn-dark fw-bold rounded-pill px-4 py-2">CERRAR</button>
    </div>

    <div class="alert-cierre text-center">
        <div class="w-100">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>¡ATENCIÓN!</strong> Contá bien los billetes. Este es un <b>cierre de caja definitivo</b> y no tiene vuelta atrás.
        </div>
    </div>

    <form method="POST" id="formCierre">
        <input type="hidden" name="total_declarado" id="inputTotalDeclarado" value="0">
        
        <div class="tech-grid">
            <?php 
            // 10 Billetes + 2 Monedas = 12 Items (4x3 perfecto en escritorio)
            $valores = [20000, 10000, 2000, 1000, 500, 200, 100, 50, 20, 10, 2, 1];
            foreach($valores as $v): 
                $label = ($v <= 2) ? "MONEDA $ $v" : "$ ".number_format($v,0,'','.');
                $clase_b = ($v <= 2) ? "border-primary" : "";
            ?>
                <div class="billete-box <?php echo $clase_b; ?>">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="b-label"><?php echo $label; ?></span>
                        <span class="text-success fw-bold b-subtotal">$ 0</span>
                    </div>
                    <div class="qty-control">
                        <button type="button" class="btn-qty" onclick="cambiar(this, -1)">-</button>
                        <input type="number" class="b-input valor-input" data-valor="<?php echo $v; ?>" placeholder="0" oninput="actualizar()">
                        <button type="button" class="btn-qty" onclick="cambiar(this, 1)">+</button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </form>
    <?php endif; ?>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script>
    const inputs = document.querySelectorAll('.valor-input');
    const display = document.getElementById('totalDisplay');
    const widget = document.getElementById('totalWidget');
    const inputHidden = document.getElementById('inputTotalDeclarado');

    function cambiar(btn, delta) {
        const input = btn.parentElement.querySelector('input');
        let v = parseInt(input.value) || 0;
        v += delta; if(v < 0) v = 0;
        input.value = v;
        actualizar();
    }

    function actualizar() {
        let t = 0;
        inputs.forEach(i => {
            let cant = parseInt(i.value) || 0;
            let val = parseInt(i.dataset.valor);
            let sub = cant * val;
            i.closest('.billete-box').querySelector('.b-subtotal').innerText = '$' + sub.toLocaleString('es-AR');
            t += sub;
        });
        const fmt = '$ ' + t.toLocaleString('es-AR', {minimumFractionDigits: 2});
        if(display) display.innerText = fmt;
        if(widget) widget.innerText = fmt;
        inputHidden.value = t;
    }

    function confirmarCierre() {
        const monto = parseFloat(inputHidden.value);
        Swal.fire({
            title: '¿Confirmar Arqueo?',
            html: `<h1 class="fw-bold text-success mt-2">$ ${monto.toLocaleString('es-AR')}</h1>`,
            icon: 'warning',
            background: '#1e293b', color: '#fff',
            showCancelButton: true, confirmButtonText: 'SÍ, CERRAR', cancelButtonText: 'REVISAR',
            confirmButtonColor: '#22c55e', cancelButtonColor: '#475569',
            borderRadius: '20px'
        }).then((r) => { if(r.isConfirmed) document.getElementById('formCierre').submit(); });
    }
</script>
<?php require_once 'includes/layout_footer.php'; ?>