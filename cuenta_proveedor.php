<?php
// cuenta_proveedor.php - DISEÑO PREMIUM
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. CONEXIÓN
if (file_exists('db.php')) require_once 'db.php';
elseif (file_exists('includes/db.php')) require_once 'includes/db.php';
else die("Error: No se encuentra db.php");

// 2. SEGURIDAD
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] > 2) {
    header("Location: dashboard.php"); exit;
}

// Validamos que el ID sea numérico
$id_proveedor = intval($_GET['id'] ?? 0);
if ($id_proveedor <= 0) { header("Location: proveedores.php"); exit; }
if (!$id_proveedor) { header("Location: proveedores.php"); exit; }

// 3. DATOS DEL PROVEEDOR
$stmt = $conexion->prepare("SELECT * FROM proveedores WHERE id = ?");
$stmt->execute([$id_proveedor]);
$prov = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$prov) die("Proveedor no encontrado.");

// 4. REGISTRAR MOVIMIENTO
$msg = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tipo = $_POST['tipo'];
    $monto = (float)$_POST['monto'];
    $desc = trim($_POST['descripcion']);
    $comp = trim($_POST['comprobante']);
    $fecha = $_POST['fecha'] . ' ' . date('H:i:s');
    $id_user = $_SESSION['usuario_id'];

    if ($monto > 0) {
        $sql = "INSERT INTO movimientos_proveedores (id_proveedor, tipo, monto, descripcion, comprobante, fecha, id_usuario) VALUES (?, ?, ?, ?, ?, ?, ?)";
        $conexion->prepare($sql)->execute([$id_proveedor, $tipo, $monto, $desc, $comp, $fecha, $id_user]);
        header("Location: cuenta_proveedor.php?id=$id_proveedor&msg=ok"); exit;
    }
}

// 5. OBTENER SALDO E HISTORIAL
$historial = $conexion->prepare("SELECT * FROM movimientos_proveedores WHERE id_proveedor = ? ORDER BY fecha DESC");
$historial->execute([$id_proveedor]);
$movimientos = $historial->fetchAll(PDO::FETCH_ASSOC);

// Calculo de Saldo
$sqlSaldo = "SELECT 
    (SELECT COALESCE(SUM(monto),0) FROM movimientos_proveedores WHERE id_proveedor = ? AND tipo = 'compra') - 
    (SELECT COALESCE(SUM(monto),0) FROM movimientos_proveedores WHERE id_proveedor = ? AND tipo = 'pago') as saldo";
$stmtSaldo = $conexion->prepare($sqlSaldo);
$stmtSaldo->execute([$id_proveedor, $id_proveedor]);
$saldo_total = $stmtSaldo->fetchColumn();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Cta. Cte. <?php echo $prov['empresa']; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        body { background-color: #f4f6f9; font-family: 'Inter', sans-serif; }
        .header-gradient {
            background: linear-gradient(135deg, #0f2027 0%, #203a43 50%, #2c5364 100%); /* Gradiente Oscuro Elegante */
            color: white; padding: 30px 0; border-radius: 0 0 30px 30px; margin-bottom: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        .saldo-card {
            background: rgba(255,255,255,0.1); backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2); border-radius: 15px; padding: 20px;
        }
        .card-custom { border: none; border-radius: 15px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); overflow: hidden; }
    </style>
</head>
<body>

<?php 
if(file_exists('menu.php')) include 'menu.php'; 
elseif(file_exists('includes/menu.php')) include 'includes/menu.php';
?>

<div class="header-gradient">
    <div class="container">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3">
            <div>
                <a href="proveedores.php" class="text-white-50 text-decoration-none small fw-bold mb-2 d-inline-block"><i class="bi bi-arrow-left"></i> VOLVER A LISTA</a>
                <h1 class="fw-bold mb-0"><?php echo $prov['empresa']; ?></h1>
                <div class="opacity-75">
                    <i class="bi bi-person"></i> <?php echo $prov['contacto']; ?> &nbsp;|&nbsp; 
                    <i class="bi bi-whatsapp"></i> <?php echo $prov['telefono']; ?>
                </div>
            </div>
            
            <div class="saldo-card text-center" style="min-width: 250px;">
                <small class="text-uppercase text-white-50 fw-bold letter-spacing-1">Saldo Deudor</small>
                <div class="display-6 fw-bold <?php echo ($saldo_total > 0) ? 'text-warning' : 'text-white'; ?>">
                    $ <?php echo number_format($saldo_total, 2, ',', '.'); ?>
                </div>
                <small class="text-white small"><?php echo ($saldo_total > 0) ? 'A pagar' : 'Al día'; ?></small>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <div class="row g-4">
        
        <div class="col-lg-4">
            <div class="card card-custom h-100">
                <div class="card-header bg-white fw-bold py-3 border-bottom-0">
                    <i class="bi bi-pencil-square text-primary"></i> Registrar Movimiento
                </div>
                <div class="card-body bg-light">
                    <form method="POST">
                        <div class="card border-0 mb-3">
                            <div class="card-body p-2">
                                <label class="small fw-bold text-muted mb-2 d-block">¿Qué vas a registrar?</label>
                                <div class="btn-group w-100" role="group">
                                    <input type="radio" class="btn-check" name="tipo" id="t_compra" value="compra" checked onchange="toggleTipo()">
                                    <label class="btn btn-outline-danger fw-bold" for="t_compra">Factura</label>
                                    
                                    <input type="radio" class="btn-check" name="tipo" id="t_pago" value="pago" onchange="toggleTipo()">
                                    <label class="btn btn-outline-success fw-bold" for="t_pago">Pago</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-floating mb-3">
                            <input type="number" step="0.01" name="monto" class="form-control fw-bold" id="floatingMonto" placeholder="Monto" required>
                            <label for="floatingMonto">Monto ($)</label>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-6">
                                <div class="form-floating">
                                    <input type="date" name="fecha" class="form-control" id="floatingFecha" value="<?php echo date('Y-m-d'); ?>" required>
                                    <label for="floatingFecha">Fecha</label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-floating">
                                    <input type="text" name="comprobante" class="form-control" id="floatingComp" placeholder="N°">
                                    <label for="floatingComp">N° Comp.</label>
                                </div>
                            </div>
                        </div>

                        <div class="form-floating mb-4">
                            <textarea name="descripcion" class="form-control" placeholder="Detalle" id="floatingDesc" style="height: 100px"></textarea>
                            <label for="floatingDesc">Notas / Detalle</label>
                        </div>

                        <button type="submit" class="btn btn-dark w-100 py-3 fw-bold shadow-sm" id="btnAccion">
                            REGISTRAR FACTURA <i class="bi bi-arrow-right"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card card-custom">
                <div class="card-header bg-white fw-bold py-3 border-bottom d-flex justify-content-between">
                    <span><i class="bi bi-clock-history text-secondary"></i> Historial de Movimientos</span>
                    <span class="badge bg-light text-dark border"><?php echo count($movimientos); ?> Registros</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light small text-muted text-uppercase">
                            <tr>
                                <th class="ps-4">Fecha</th>
                                <th>Concepto</th>
                                <th class="text-end">Debe</th>
                                <th class="text-end pe-4">Haber</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(count($movimientos) > 0): ?>
                                <?php foreach($movimientos as $m): 
                                    $es_compra = ($m['tipo'] == 'compra');
                                ?>
                                <tr>
                                    <td class="ps-4 text-nowrap">
                                        <div class="fw-bold"><?php echo date('d/m/Y', strtotime($m['fecha'])); ?></div>
                                        <small class="text-muted"><?php echo date('H:i', strtotime($m['fecha'])); ?></small>
                                    </td>
                                    <td>
                                        <div class="d-flex align-items-center">
                                            <div class="rounded-circle p-2 me-2 <?php echo $es_compra ? 'bg-danger bg-opacity-10 text-danger' : 'bg-success bg-opacity-10 text-success'; ?>">
                                                <i class="bi <?php echo $es_compra ? 'bi-receipt' : 'bi-cash-stack'; ?>"></i>
                                            </div>
                                            <div>
                                                <div class="fw-bold small text-dark"><?php echo $es_compra ? 'Factura / Compra' : 'Pago Realizado'; ?></div>
                                                <div class="text-muted small lh-1">
                                                    <?php echo $m['comprobante'] ? '<span class="badge bg-light text-dark border">'.$m['comprobante'].'</span> ' : ''; ?>
                                                    <?php echo $m['descripcion']; ?>
                                                </div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="text-end fw-bold text-danger">
                                        <?php echo $es_compra ? '$ '.number_format($m['monto'], 2) : '-'; ?>
                                    </td>
                                    <td class="text-end fw-bold text-success pe-4">
                                        <?php echo !$es_compra ? '$ '.number_format($m['monto'], 2) : '-'; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="4" class="text-center py-5 text-muted">No hay movimientos registrados.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    if(new URLSearchParams(window.location.search).get('msg') === 'ok') {
        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Movimiento guardado', showConfirmButton: false, timer: 3000 });
    }

    function toggleTipo() {
        const esCompra = document.getElementById('t_compra').checked;
        const btn = document.getElementById('btnAccion');
        if(esCompra) {
            btn.innerHTML = 'REGISTRAR FACTURA <i class="bi bi-arrow-right"></i>';
            btn.className = 'btn btn-dark w-100 py-3 fw-bold shadow-sm';
        } else {
            btn.innerHTML = 'REGISTRAR PAGO <i class="bi bi-check-lg"></i>';
            btn.className = 'btn btn-success w-100 py-3 fw-bold shadow-sm';
        }
    }
</script>
</body>
</html>