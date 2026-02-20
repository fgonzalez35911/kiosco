<?php
// devoluciones.php - FINAL (Banner Azul Estandarizado)
session_start();
error_reporting(0); 

// 1. CONEXIÓN
if (file_exists('includes/db.php')) { require_once 'includes/db.php'; } 
elseif (file_exists('db.php')) { require_once 'db.php'; } 
else { die("Error: No se encuentra db.php"); }

// 2. SEGURIDAD
if (!isset($_SESSION['usuario_id'])) { echo "<script>window.location='index.php';</script>"; exit; }
$user_id = $_SESSION['usuario_id'];

// 3. ASEGURAR TABLA
try {
    $conexion->exec("CREATE TABLE IF NOT EXISTS devoluciones (id INT AUTO_INCREMENT PRIMARY KEY, id_venta_original INT, id_producto INT, cantidad DECIMAL(10,2), monto_devuelto DECIMAL(10,2), fecha DATETIME, id_usuario INT)");
} catch(Exception $e) { }

// 4. DETECTAR CAJA ABIERTA
$stmtCaja = $conexion->prepare("SELECT id FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta'");
$stmtCaja->execute([$user_id]);
$cajaAbierta = $stmtCaja->fetch(PDO::FETCH_ASSOC);
$id_caja_actual = $cajaAbierta ? $cajaAbierta['id'] : null;

// 5. PROCESAR DEVOLUCIÓN
if (isset($_POST['devolver'])) {
    $id_venta = $_POST['id_venta'];
    $id_producto = $_POST['id_producto'];
    $cantidad = $_POST['cantidad'];
    $monto = $_POST['monto'];

    $mensaje = '';
    $tipo_msg = '';

    try {
        $conexion->beginTransaction();

        $check = $conexion->prepare("SELECT id FROM devoluciones WHERE id_venta_original = ? AND id_producto = ?");
        $check->execute([$id_venta, $id_producto]);
        if ($check->rowCount() > 0) throw new Exception("¡Este producto ya fue devuelto anteriormente!");

        $stmtV = $conexion->prepare("SELECT metodo_pago, id_cliente FROM ventas WHERE id = ?");
        $stmtV->execute([$id_venta]);
        $ventaOriginal = $stmtV->fetch(PDO::FETCH_ASSOC);

                $motivo_dev = $_POST['motivo_dev'] ?? 'Reingreso'; // Recibimos el motivo del modal que haremos luego
        
        // 1. Registramos la devolución incluyendo el motivo real
        $stmt = $conexion->prepare("INSERT INTO devoluciones (id_venta_original, id_producto, cantidad, monto_devuelto, motivo, fecha, id_usuario) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
        $stmt->execute([$id_venta, $id_producto, $cantidad, $monto, $motivo_dev, $user_id]);

        // 2. Decisión de Stock vs Merma
        if ($motivo_dev === 'Reingreso') {
            // Si está bien, vuelve al stock disponible para la venta
            $stmtUP = $conexion->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?");
            $stmtUP->execute([$cantidad, $id_producto]);
        } else {
            // Si está roto o vencido, NO vuelve al stock. Se registra como Merma.
            $motivo_merma = "Devolución Ticket #$id_venta: $motivo_dev";
            $stmtM = $conexion->prepare("INSERT INTO mermas (id_producto, cantidad, motivo, fecha, id_usuario) VALUES (?, ?, ?, NOW(), ?)");
            $stmtM->execute([$id_producto, $cantidad, $motivo_merma, $user_id]);
        }


        if ($ventaOriginal) {
            if ($ventaOriginal['metodo_pago'] === 'CtaCorriente' && $ventaOriginal['id_cliente'] > 1) {
                $concepto = "Devolución Prod. Ticket #$id_venta";
                $stmtCC = $conexion->prepare("INSERT INTO movimientos_cc (id_cliente, id_venta, id_usuario, tipo, monto, concepto, fecha) VALUES (?, ?, ?, 'haber', ?, ?, NOW())");
                $stmtCC->execute([$ventaOriginal['id_cliente'], $id_venta, $user_id, $monto, $concepto]);
                $conexion->prepare("UPDATE clientes SET saldo_actual = saldo_actual - ? WHERE id = ?")->execute([$monto, $ventaOriginal['id_cliente']]);
                
                $mensaje = "Devolución EXITOSA. Se ajustó la deuda.";
                $tipo_msg = "success";
            } elseif ($ventaOriginal['metodo_pago'] === 'Efectivo') {
                if ($id_caja_actual) {
                    $descGasto = "Devolución Efectivo Ticket #$id_venta";
                    $conexion->prepare("INSERT INTO gastos (descripcion, monto, categoria, fecha, id_usuario, id_caja_sesion) VALUES (?, ?, 'Devoluciones', NOW(), ?, ?)")->execute([$descGasto, $monto, $user_id, $id_caja_actual]);
                    
                    $mensaje = "Devolución EXITOSA. Se restó de la caja.";
                    $tipo_msg = "success";
                } else {
                    $mensaje = "Stock devuelto, pero NO hay caja abierta para descontar el dinero.";
                    $tipo_msg = "warning";
                }
            } else {
                $mensaje = "Devolución OK (Pago original: " . $ventaOriginal['metodo_pago'] . ").";
                $tipo_msg = "info";
            }
        }

        $conexion->commit();
        echo "<script>window.location.href='devoluciones.php?id_ticket=$id_venta&msg=".urlencode($mensaje)."&tipo=$tipo_msg';</script>";
        exit;
        
    } catch (Exception $e) {
        if ($conexion->inTransaction()) { $conexion->rollBack(); }
        echo "<script>alert('" . addslashes($e->getMessage()) . "'); window.location.href='devoluciones.php?id_ticket=$id_venta';</script>";
        exit;
    }
}

// 6. DATOS VISTA
$venta = null;
$items = [];
$productos_devueltos = [];
$id_ticket_buscado = $_GET['id_ticket'] ?? '';

if ($id_ticket_buscado > 0) {
    $stmt = $conexion->prepare("SELECT v.*, c.nombre as cliente FROM ventas v LEFT JOIN clientes c ON v.id_cliente = c.id WHERE v.id = ?");
    $stmt->execute([$id_ticket_buscado]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($venta) {
        $stmtDet = $conexion->prepare("SELECT d.*, p.descripcion FROM detalle_ventas d JOIN productos p ON d.id_producto = p.id WHERE d.id_venta = ?");
        $stmtDet->execute([$id_ticket_buscado]);
        $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

        $stmtDevs = $conexion->prepare("SELECT id_producto FROM devoluciones WHERE id_venta_original = ?");
        $stmtDevs->execute([$id_ticket_buscado]);
        $productos_devueltos = $stmtDevs->fetchAll(PDO::FETCH_COLUMN);
    }
}

// --- ESTADÍSTICAS ÚTILES ---
$totalDevs = $conexion->query("SELECT COUNT(*) FROM devoluciones")->fetchColumn();
$devsHoy = $conexion->query("SELECT COUNT(*) FROM devoluciones WHERE DATE(fecha) = CURDATE()")->fetchColumn();
$plataHoy = $conexion->query("SELECT COALESCE(SUM(monto_devuelto),0) FROM devoluciones WHERE DATE(fecha) = CURDATE()")->fetchColumn();

// --- LISTADO FILTRABLE ---
if(isset($_GET['filtro']) && $_GET['filtro'] == 'hoy') {
    // Si filtran por HOY: mostramos solo las ventas que tuvieron devolución hoy
    $sql = "SELECT v.id, v.total, v.fecha, c.nombre, 1 as tiene_dev 
            FROM ventas v 
            JOIN devoluciones d ON v.id = d.id_venta_original 
            LEFT JOIN clientes c ON v.id_cliente = c.id 
            WHERE DATE(d.fecha) = CURDATE() 
            GROUP BY v.id ORDER BY d.fecha DESC";
} else {
    // Normal: Últimas 15 ventas generales
    $sql = "SELECT v.id, v.total, v.fecha, c.nombre, (SELECT COUNT(*) FROM devoluciones d WHERE d.id_venta_original = v.id) as tiene_dev FROM ventas v LEFT JOIN clientes c ON v.id_cliente = c.id ORDER BY v.id DESC LIMIT 15";
}
$ultimas = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);
?>
<?php include 'includes/layout_header.php'; ?>

<style>
    /* Estilos Banner Azul (Fuera del container) */
    .header-blue {
        background-color: #102A57;
        color: white;
        padding: 40px 0;
        margin-bottom: 30px;
        border-radius: 0 0 30px 30px;
        box-shadow: 0 4px 15px rgba(16, 42, 87, 0.25);
        position: relative;
        overflow: hidden;
    }
    .bg-icon-large {
        position: absolute; top: 50%; right: 20px;
        transform: translateY(-50%) rotate(-10deg);
        font-size: 10rem; opacity: 0.1; color: white; pointer-events: none;
    }
    .stat-card {
        border: none; border-radius: 15px; padding: 15px 20px;
        box-shadow: 0 4px 10px rgba(0,0,0,0.05); transition: transform 0.2s;
        background: white; height: 100%; display: flex; align-items: center; justify-content: space-between;
    }
    .stat-card:hover { transform: translateY(-3px); }
    .icon-box { width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }

    /* Estilos Tabla Devoluciones */
    .row-devuelto { background-color: #ffe6e6 !important; color: #dc3545; }
    .row-devuelto td { color: #dc3545; text-decoration: line-through; }
    .badge-dev { font-size: 0.7rem; }
</style>

<div class="header-blue">
    <i class="bi bi-arrow-counterclockwise bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="fw-bold mb-0">Gestión de Devoluciones</h2>
                <p class="opacity-75 mb-0">Control de reintegros y stock</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="stat-card" onclick="window.location.href='devoluciones.php'" style="cursor: pointer;">
                    <div>
                        <h6 class="text-muted text-uppercase small fw-bold mb-1">Total Histórico</h6>
                        <h2 class="mb-0 fw-bold text-dark"><?php echo $totalDevs; ?></h2>
                    </div>
                    <div class="icon-box bg-primary bg-opacity-10 text-primary">
                        <i class="bi bi-clock-history"></i>
                    </div>
                </div>
            </div>
            
            <div class="col-12 col-md-4">
                <div class="stat-card" onclick="window.location.href='devoluciones.php?filtro=hoy'" style="cursor: pointer;">
                    <div>
                        <h6 class="text-muted text-uppercase small fw-bold mb-1">Devoluciones Hoy</h6>
                        <h2 class="mb-0 fw-bold <?php echo ($devsHoy > 0) ? 'text-danger' : 'text-dark'; ?>">
                            <?php echo $devsHoy; ?>
                        </h2>
                    </div>
                    <div class="icon-box bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-arrow-return-left"></i>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="stat-card" onclick="window.location.href='devoluciones.php?filtro=hoy'" style="cursor: pointer;">
                    <div>
                        <h6 class="text-muted text-uppercase small fw-bold mb-1">Dinero Devuelto (Hoy)</h6>
                        <h2 class="mb-0 fw-bold text-dark">$<?php echo number_format($plataHoy, 0, ',', '.'); ?></h2>
                    </div>
                    <div class="icon-box bg-warning bg-opacity-10 text-warning">
                        <i class="bi bi-currency-dollar"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5 mt-4">

    <div class="row">
        <div class="col-md-4">
            <div class="card shadow-sm mb-3 border-0">
                <div class="card-header bg-dark text-white fw-bold">Buscar Ticket</div>
                <div class="card-body">
                    <form method="GET" class="d-flex gap-2">
                        <input type="number" name="id_ticket" class="form-control" placeholder="N° Ticket" required value="<?php echo htmlspecialchars($id_ticket_buscado); ?>">
                        <button class="btn btn-primary fw-bold">Buscar</button>
                    </form>
                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white fw-bold border-bottom">Últimos Tickets</div>
                <div class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">
                    <?php foreach($ultimas as $u): ?>
                        <a href="devoluciones.php?id_ticket=<?php echo $u['id']; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <div>
                                <span class="fw-bold text-primary">#<?php echo $u['id']; ?></span>
                                <small class="d-block text-muted" style="font-size:0.75rem"><?php echo substr($u['nombre'] ?? 'Consumidor Final', 0, 15); ?></small>
                            </div>
                            <div class="text-end">
                                <span class="fw-bold text-dark">$<?php echo number_format($u['total'], 0); ?></span>
                                <small class="d-block text-muted" style="font-size:0.75rem"><?php echo date('d/m H:i', strtotime($u['fecha'])); ?></small>
                                <?php if($u['tiene_dev'] > 0): ?>
                                    <span class="badge bg-warning text-dark badge-dev d-block mt-1">⚠️ Con Devolución</span>
                                <?php endif; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <?php if($venta): ?>
                <div class="card shadow border-0">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <span class="fw-bold fs-5">Ticket #<?php echo $venta['id']; ?></span>
                        <span class="badge bg-white text-primary"><?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?></span>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-light border d-flex justify-content-between align-items-center">
                            <div>
                                <strong>Cliente:</strong> <?php echo $venta['cliente'] ?? 'Consumidor Final'; ?><br>
                                <small class="text-muted">Pago: <?php echo $venta['metodo_pago']; ?></small>
                            </div>
                            <span class="badge bg-success">Completada</span>
                        </div>
                        
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead class="table-light">
                                    <tr><th>Producto</th><th>Cant.</th><th>Precio</th><th>Subtotal</th><th>Acción</th></tr>
                                </thead>
                                <tbody>
                                    <?php foreach($items as $i): 
                                        $ya_devuelto = in_array($i['id_producto'], $productos_devueltos);
                                        $clase_row = $ya_devuelto ? 'row-devuelto' : '';
                                    ?>
                                    <tr class="<?php echo $clase_row; ?>">
                                        <td><?php echo $i['descripcion']; ?><?php if($ya_devuelto): ?><span class="badge bg-danger ms-2">DEVUELTO</span><?php endif; ?></td>
                                        <td class="text-center fw-bold"><?php echo floatval($i['cantidad']); ?></td>
                                        <td class="text-end">$<?php echo number_format($i['precio_historico'] ?? $i['precio_unitario'] ?? 0, 2); ?></td>
                                        <td class="text-end fw-bold">$<?php echo number_format($i['subtotal'], 2); ?></td>
                                        <td class="text-center">
                                            <?php if($ya_devuelto): ?>
                                                <button class="btn btn-secondary btn-sm fw-bold" disabled><i class="bi bi-x-circle"></i> Ya Devuelto</button>
                                            <?php else: ?>
                                                <form method="POST" class="form-devolucion">
                                                    <input type="hidden" name="devolver" value="1">
                                                    <input type="hidden" name="id_venta" value="<?php echo $venta['id']; ?>">
                                                    <input type="hidden" name="id_producto" value="<?php echo $i['id_producto']; ?>">
                                                    <input type="hidden" name="cantidad" value="<?php echo $i['cantidad']; ?>">
                                                    <input type="hidden" name="monto" value="<?php echo $i['subtotal']; ?>">
                                                    <button type="button" class="btn btn-outline-danger btn-sm fw-bold btn-confirmar-dev"><i class="bi bi-arrow-return-left"></i> Devolver</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="alert alert-secondary text-center py-5 border-0">
                    <i class="bi bi-receipt display-1 opacity-25"></i>
                    <h4 class="mt-3 text-muted fw-light">Selecciona un ticket para ver detalles</h4>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    <?php if(isset($_GET['msg'])): ?>
        // Usamos json_encode para asegurar que el texto sea seguro para JS
        Swal.fire({
            title: 'Operación Finalizada',
            text: <?php echo json_encode(urldecode($_GET['msg'])); ?>,
            icon: "<?php echo $_GET['tipo'] ?? 'info'; ?>",
            confirmButtonColor: '#0d6efd',
            confirmButtonText: 'Entendido'
        }).then(() => {
            const url = new URL(window.location.href);
            url.searchParams.delete('msg');
            url.searchParams.delete('tipo');
            window.history.replaceState({}, document.title, url);
        });
    <?php endif; ?>

    document.querySelectorAll('.btn-confirmar-dev').forEach(btn => {
        btn.addEventListener('click', function() {
            let form = this.closest('form');
            Swal.fire({
                title: '¿Confirmar Devolución?',
                html: "Se devolverá el stock y se descontará el dinero.<br><b>Esta acción no se puede deshacer.</b>",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, registrar devolución',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    this.disabled = true;
                    this.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Procesando...';
                    form.submit();
                }
            });
        });
    });
</script>
<?php include 'includes/layout_footer.php'; ?>