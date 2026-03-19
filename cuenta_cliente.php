<?php
// cuenta_cliente.php - ESTÁNDAR VANGUARD POS (Banner dinámico y orden mobile corregido)
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. CONEXIÓN
if (file_exists('db.php')) require_once 'db.php';
elseif (file_exists('includes/db.php')) require_once 'includes/db.php';
else die("Error: db.php no encontrado");

// 2. SEGURIDAD
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php"); exit;
}

// Validamos que el ID sea un número y exista
$id_cliente = intval($_GET['id'] ?? 0);
if ($id_cliente <= 0) { header("Location: clientes.php"); exit; }

// --- CANDADO: PERMISO DE LECTURA ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = ($_SESSION['rol'] <= 2);
if (!$es_admin && !in_array('clientes_ver_cc', $permisos)) { 
    header("Location: clientes.php"); exit; 
}

// DATOS CLIENTE
$stmt = $conexion->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$id_cliente]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cliente) die("Cliente no encontrado.");

// REGISTRAR MOVIMIENTO MANUAL
$msg = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // --- CANDADO: PERMISO DE ESCRITURA ---
    if (!$es_admin && !in_array('clientes_saldar_deuda', $permisos)) {
        die("Error: No tienes permiso para registrar pagos o deudas.");
    }
    try {
        $accion = $_POST['accion']; // 'pago' (haber) o 'deuda' (debe)
        $monto = (float)$_POST['monto'];
        $concepto = trim($_POST['concepto']);
        $id_user = $_SESSION['usuario_id'];
        
        $tipo_db = ($accion == 'deuda') ? 'debe' : 'haber';

        if ($monto > 0) {
            $sql = "INSERT INTO movimientos_cc (id_cliente, tipo, monto, concepto, id_usuario, fecha) VALUES (?, ?, ?, ?, ?, NOW())";
            $stmt = $conexion->prepare($sql);
            $stmt->execute([$id_cliente, $tipo_db, $monto, $concepto, $id_user]);
            
            header("Location: cuenta_cliente.php?id=$id_cliente&msg=ok"); exit;
        }
    } catch (Exception $e) {
        $msg = '<div class="alert alert-danger shadow-sm mb-4">Error: '.$e->getMessage().'</div>';
    }
}

if(isset($_GET['msg']) && $_GET['msg'] == 'ok') {
    $msg = '<div class="alert alert-success shadow-sm mb-4 fw-bold"><i class="bi bi-check-circle-fill"></i> Movimiento registrado correctamente.</div>';
}

// OBTENER HISTORIAL Y SALDO
$historial = [];
$saldo_actual = 0;
try {
    // Historial
    $stmtHist = $conexion->prepare("SELECT * FROM movimientos_cc WHERE id_cliente = ? ORDER BY fecha DESC");
    $stmtHist->execute([$id_cliente]);
    $historial = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

    // Saldo Total
    $sqlSaldo = "SELECT 
        (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = ? AND tipo = 'debe') - 
        (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = ? AND tipo = 'haber')";
    $stmtS = $conexion->prepare($sqlSaldo);
    $stmtS->execute([$id_cliente, $id_cliente]);
    $saldo_actual = $stmtS->fetchColumn();

} catch (Exception $e) { $msg = $e->getMessage(); }

// Cálculos para widgets
$limite = floatval($cliente['limite_credito']);
$disponible = ($limite > 0) ? ($limite - $saldo_actual) : 'Ilimitado';

// Configuramos layout header
require_once 'includes/layout_header.php'; 

// --- DEFINICIÓN DEL BANNER DINÁMICO ESTANDARIZADO ---
$titulo = strtoupper($cliente['nombre']);
$subtitulo = "DNI: " . ($cliente['dni'] ?: '--') . " | Tel: " . ($cliente['telefono'] ?: '--');
$icono_bg = "bi-person-badge"; // Ícono de cliente

$botones = [
    ['texto' => 'VOLVER', 'link' => "clientes.php", 'icono' => 'bi-arrow-left', 'class' => 'btn btn-light btn-sm fw-bold rounded-pill px-3 shadow-sm border text-primary']
];

$widgets = [
    ['label' => 'Saldo Actual', 'valor' => '$'.number_format($saldo_actual, 2, ',', '.'), 'icono' => 'bi-cash-stack', 'border' => ($saldo_actual > 0) ? 'border-danger' : 'border-success', 'icon_bg' => ($saldo_actual > 0) ? 'bg-danger bg-opacity-20 text-danger' : 'bg-success bg-opacity-20 text-success'],
    ['label' => 'Límite Crédito', 'valor' => ($limite > 0) ? '$'.number_format($limite, 0, ',', '.') : 'Ilimitado', 'icono' => 'bi-shield-lock', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Disponible', 'valor' => ($limite > 0) ? '$'.number_format($disponible, 2, ',', '.') : '∞', 'icono' => 'bi-cart-check', 'border' => 'border-info', 'icon_bg' => 'bg-info bg-opacity-20']
];

$centrar_botones = true; // Usamos el "interruptor" para que quede prolijo
include 'includes/componente_banner.php'; 
?>

<div class="container pb-5 mt-n4" style="position: relative; z-index: 20;">
    <div class="row g-4">
        
        <?php if($es_admin || in_array('clientes_saldar_deuda', $permisos)): ?>
        <div class="col-lg-4 order-2 order-lg-1">
            <div class="card card-form sticky-top shadow-sm border-0 rounded-4" style="top: 20px; z-index: 1;">
                <div class="card-header bg-success text-white fw-bold py-3 header-form" id="headerForm">
                    <i class="bi bi-pencil-square me-2"></i> Registrar Movimiento
                </div>
                <div class="card-body p-4 bg-white rounded-bottom-4">
                    <?php echo $msg; ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="fw-bold small text-muted mb-1 text-uppercase">Tipo de Movimiento</label>
                            <select name="accion" class="form-select form-select-lg fw-bold border-success text-success bg-light" id="tipoSelect" onchange="cambiarColor()">
                                <option value="pago" class="text-success" selected>💵 Recibir Pago (Baja Deuda)</option>
                                <option value="deuda" class="text-danger">📝 Fiado (Sube Deuda)</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="fw-bold small text-muted mb-1 text-uppercase">Monto ($)</label>
                            <div class="input-group input-group-lg shadow-sm rounded-3 overflow-hidden">
                                <span class="input-group-text border-0 bg-light fw-bold text-muted">$</span>
                                <input type="number" name="monto" class="form-control fw-bold bg-light border-0" step="0.01" required placeholder="0.00">
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="fw-bold small text-muted mb-1 text-uppercase">Detalle / Concepto</label>
                            <textarea name="concepto" class="form-control bg-light border-0 shadow-sm rounded-3" rows="3" placeholder="Ej: Pago parcial, Entrega de mercadería..."></textarea>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-success btn-lg fw-bold py-3 shadow-sm rounded-pill" id="btnSubmit">
                                CONFIRMAR OPERACIÓN
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-lg-<?php echo ($es_admin || in_array('clientes_saldar_deuda', $permisos)) ? '8' : '12'; ?> order-1 order-lg-2">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden h-100">
                <div class="card-header bg-white py-3 fw-bold border-bottom d-flex align-items-center">
                    <i class="bi bi-clock-history text-primary me-2 fs-5"></i> Historial de Movimientos
                </div>
                <div class="card-body p-0 bg-white">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-uppercase text-muted">
                                <tr class="align-middle">
    <th class="py-3 text-center">Fecha</th>
    <th class="text-center">Concepto</th>
    <th class="text-center">Deuda</th>
    <th class="text-center">Pagos</th>
</tr>
                            </thead>
                            <tbody>
                                <?php if(count($historial) > 0): ?>
                                    <?php foreach($historial as $m): ?>
                                    <tr>
                                        <td class="ps-4 small text-muted" style="min-width: 100px;">
                                            <div class="fw-bold text-dark"><?php echo date('d/m/y', strtotime($m['fecha'])); ?></div>
                                            <small><?php echo date('H:i', strtotime($m['fecha'])); ?> hs</small>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($m['concepto']); ?></div>
                                            <small class="text-muted" style="font-size: 0.75rem;">Ref: #<?php echo $m['id']; ?></small>
                                        </td>
                                        <td class="text-end text-danger fw-bold" style="min-width: 100px;">
                                            <?php echo ($m['tipo']=='debe') ? '-$'.number_format($m['monto'],2,',','.') : '-'; ?>
                                        </td>
                                        <td class="text-end text-success fw-bold pe-4" style="min-width: 100px;">
                                            <?php echo ($m['tipo']=='haber') ? '+$'.number_format($m['monto'],2,',','.') : '-'; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="4" class="text-center py-5 text-muted">
                                            <i class="bi bi-inbox fs-1 d-block mb-2 opacity-50"></i>
                                            Este cliente no tiene movimientos registrados.
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
        
    </div>
</div>

<script>
    function cambiarColor() {
        var tipo = document.getElementById('tipoSelect').value;
        var header = document.getElementById('headerForm');
        var btn = document.getElementById('btnSubmit');
        var select = document.getElementById('tipoSelect');
        
        if(tipo === 'deuda') {
            header.className = 'card-header bg-danger text-white fw-bold py-3 header-form';
            btn.className = 'btn btn-danger btn-lg fw-bold py-3 shadow-sm rounded-pill';
            select.className = 'form-select form-select-lg fw-bold border-danger text-danger bg-light';
        } else {
            header.className = 'card-header bg-success text-white fw-bold py-3 header-form';
            btn.className = 'btn btn-success btn-lg fw-bold py-3 shadow-sm rounded-pill';
            select.className = 'form-select form-select-lg fw-bold border-success text-success bg-light';
        }
    }
</script>

<?php include 'includes/layout_footer.php'; ?>