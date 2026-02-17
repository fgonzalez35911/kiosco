<?php
// cuenta_cliente.php - FINAL (Banner corregido: Mismo alto que el resto)
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

if (!$id_cliente) { header("Location: clientes.php"); exit; }

// DATOS CLIENTE
$stmt = $conexion->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$id_cliente]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$cliente) die("Cliente no encontrado.");

// REGISTRAR MOVIMIENTO MANUAL
$msg = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
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
    <i class="bi bi-wallet2 bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white text-uppercase"><?php echo $cliente['nombre']; ?></h2>
                <p class="opacity-75 mb-0 text-white small">
                    DNI: <?php echo $cliente['dni'] ?: '--'; ?> | Tel: <?php echo $cliente['telefono'] ?: '--'; ?>
                </p>
            </div>
            <div>
                <a href="clientes.php" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm">
                    <i class="bi bi-arrow-left me-2"></i> VOLVER
                </a>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Saldo Actual</div>
                        <div class="widget-value <?php echo ($saldo_actual > 0) ? 'text-danger' : 'text-success'; ?>">
                            $<?php echo number_format($saldo_actual, 2, ',', '.'); ?>
                        </div>
                    </div>
                    <div class="icon-box <?php echo ($saldo_actual > 0) ? 'bg-danger' : 'bg-success'; ?> bg-opacity-20 text-white">
                        <i class="bi bi-cash-stack"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-6 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Límite Crédito</div>
                        <div class="widget-value text-white">
                            <?php echo ($limite > 0) ? '$'.number_format($limite, 0, ',', '.') : 'Ilimitado'; ?>
                        </div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white">
                        <i class="bi bi-shield-lock"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-4">
                <div class="header-widget border-info">
                    <div>
                        <div class="widget-label">Disponible</div>
                        <div class="widget-value text-white">
                            <?php echo ($limite > 0) ? '$'.number_format($disponible, 2, ',', '.') : '∞'; ?>
                        </div>
                    </div>
                    <div class="icon-box bg-info bg-opacity-20 text-white">
                        <i class="bi bi-cart-check"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card card-form sticky-top" style="top: 20px; z-index: 1;">
                <div class="card-header bg-success text-white fw-bold py-3 header-form" id="headerForm">
                    <i class="bi bi-pencil-square me-2"></i> Registrar Movimiento
                </div>
                <div class="card-body p-4">
                    <?php echo $msg; ?>
                    <form method="POST">
                        <div class="mb-3">
                            <label class="fw-bold small text-muted mb-1">Tipo de Movimiento</label>
                            <select name="accion" class="form-select form-select-lg fw-bold border-success text-success" id="tipoSelect" onchange="cambiarColor()">
                                <option value="pago" class="text-success" selected>💵 Recibir Pago (Baja Deuda)</option>
                                <option value="deuda" class="text-danger">📝 Ajuste Manual (Sube Deuda)</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="fw-bold small text-muted mb-1">Monto ($)</label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text border-0 bg-light fw-bold text-muted">$</span>
                                <input type="number" name="monto" class="form-control fw-bold bg-light border-0" step="0.01" required placeholder="0.00">
                            </div>
                        </div>
                        
                        <div class="mb-4">
                            <label class="fw-bold small text-muted mb-1">Detalle / Concepto</label>
                            <textarea name="concepto" class="form-control bg-light border-0" rows="3" placeholder="Ej: Pago parcial, Entrega de mercadería..."></textarea>
                        </div>
                        
                        <div class="d-grid">
                            <button type="submit" class="btn btn-success btn-lg fw-bold py-3 shadow-sm" id="btnSubmit">
                                CONFIRMAR OPERACIÓN
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card shadow-sm border-0 rounded-4 overflow-hidden">
                <div class="card-header bg-white py-3 fw-bold border-bottom">
                    <i class="bi bi-clock-history me-2"></i> Historial de Movimientos
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light small text-uppercase">
                                <tr>
                                    <th class="ps-4">Fecha</th>
                                    <th>Concepto</th>
                                    <th class="text-end text-danger">Debe (Fiado)</th>
                                    <th class="text-end text-success pe-4">Haber (Pagos)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($historial) > 0): ?>
                                    <?php foreach($historial as $m): ?>
                                    <tr>
                                        <td class="ps-4 small text-muted" style="min-width: 100px;">
                                            <?php echo date('d/m/y H:i', strtotime($m['fecha'])); ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo $m['concepto']; ?></div>
                                            <small class="text-muted" style="font-size: 0.75rem;">Ref: #<?php echo $m['id']; ?></small>
                                        </td>
                                        <td class="text-end text-danger fw-bold" style="min-width: 100px;">
                                            <?php echo ($m['tipo']=='debe') ? '$'.number_format($m['monto'],2,',','.') : '-'; ?>
                                        </td>
                                        <td class="text-end text-success fw-bold pe-4" style="min-width: 100px;">
                                            <?php echo ($m['tipo']=='haber') ? '$'.number_format($m['monto'],2,',','.') : '-'; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted">Este cliente no tiene movimientos registrados.</td></tr>
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
            // Modo Deuda (ROJO)
            header.className = 'card-header bg-danger text-white fw-bold py-3 header-form';
            btn.className = 'btn btn-danger btn-lg fw-bold py-3 shadow-sm';
            select.className = 'form-select form-select-lg fw-bold border-danger text-danger';
        } else {
            // Modo Pago (VERDE)
            header.className = 'card-header bg-success text-white fw-bold py-3 header-form';
            btn.className = 'btn btn-success btn-lg fw-bold py-3 shadow-sm';
            select.className = 'form-select form-select-lg fw-bold border-success text-success';
        }
    }
</script>

<?php include 'includes/layout_footer.php'; ?>