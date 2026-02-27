<?php
// devoluciones.php - VERSIÓN ESTANDARIZADA CON CANDADOS
session_start();
error_reporting(0); 

if (file_exists('includes/db.php')) { require_once 'includes/db.php'; } 
elseif (file_exists('db.php')) { require_once 'db.php'; } 
else { die("Error: No se encuentra db.php"); }

if (!isset($_SESSION['usuario_id'])) { echo "<script>window.location='index.php';</script>"; exit; }

$user_id = $_SESSION['usuario_id']; // ESTO FALTABA Y ROMPÍA EL GUARDADO

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);

// Candado: Acceso a la página
if (!$es_admin && !in_array('ver_devoluciones', $permisos)) { 
    echo "<script>window.location='dashboard.php';</script>"; exit; 
}

$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if ($dataC && isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav'];
    }
} catch(Exception $e) { }

try { $conexion->exec("CREATE TABLE IF NOT EXISTS devoluciones (id INT AUTO_INCREMENT PRIMARY KEY, id_venta_original INT, id_producto INT, cantidad DECIMAL(10,2), monto_devuelto DECIMAL(10,2), motivo VARCHAR(150), fecha DATETIME, id_usuario INT)"); } catch(Exception $e) { }

$stmtCaja = $conexion->prepare("SELECT id FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta'");
$stmtCaja->execute([$user_id]);
$cajaAbierta = $stmtCaja->fetch(PDO::FETCH_ASSOC);
$id_caja_actual = $cajaAbierta ? $cajaAbierta['id'] : 1;

// PROCESAR DEVOLUCIÓN
if (isset($_POST['devolver'])) {
    $id_venta = $_POST['id_venta'];
    $id_producto = $_POST['id_producto'];
    $cantidad = $_POST['cantidad'];
    $monto = $_POST['monto'];
    $motivo_dev = $_POST['motivo_dev'] ?? 'Reingreso';

    try {
        $conexion->beginTransaction();
        $check = $conexion->prepare("SELECT id FROM devoluciones WHERE id_venta_original = ? AND id_producto = ?");
        $check->execute([$id_venta, $id_producto]);
        if ($check->rowCount() > 0) throw new Exception("¡Este producto ya fue devuelto!");

        $stmtV = $conexion->prepare("SELECT metodo_pago, id_cliente FROM ventas WHERE id = ?");
        $stmtV->execute([$id_venta]);
        $ventaOriginal = $stmtV->fetch(PDO::FETCH_ASSOC);

        $stmt = $conexion->prepare("INSERT INTO devoluciones (id_venta_original, id_producto, cantidad, monto_devuelto, motivo, fecha, id_usuario) VALUES (?, ?, ?, ?, ?, NOW(), ?)");
        $stmt->execute([$id_venta, $id_producto, $cantidad, $monto, $motivo_dev, $user_id]);

        if ($motivo_dev === 'Reingreso') {
            $conexion->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?")->execute([$cantidad, $id_producto]);
        } else {
            $motivo_merma = "Devolución Ticket #$id_venta: $motivo_dev";
            $conexion->prepare("INSERT INTO mermas (id_producto, cantidad, motivo, fecha, id_usuario) VALUES (?, ?, ?, NOW(), ?)")->execute([$id_producto, $cantidad, $motivo_merma, $user_id]);
        }

        if ($ventaOriginal['metodo_pago'] === 'CtaCorriente' && $ventaOriginal['id_cliente'] > 1) {
            $conexion->prepare("UPDATE clientes SET saldo_actual = saldo_actual - ? WHERE id = ?")->execute([$monto, $ventaOriginal['id_cliente']]);
            $conexion->prepare("INSERT INTO movimientos_cc (id_cliente, id_venta, id_usuario, tipo, monto, concepto, fecha) VALUES (?, ?, ?, 'haber', ?, ?, NOW())")->execute([$ventaOriginal['id_cliente'], $id_venta, $user_id, $monto, "Devolución Ticket #$id_venta"]);
            $msg = "Devolución EXITOSA. Se ajustó la deuda.";
        } elseif ($ventaOriginal['metodo_pago'] === 'Efectivo' && $id_caja_actual) {
            $conexion->prepare("INSERT INTO gastos (descripcion, monto, categoria, fecha, id_usuario, id_caja_sesion) VALUES (?, ?, 'Devoluciones', NOW(), ?, ?)")->execute(["Devolución Ticket #$id_venta", $monto, $user_id, $id_caja_actual]);
            $msg = "Devolución EXITOSA. Dinero restado de caja.";
        } else {
            $msg = "Devolución registrada correctamente.";
        }

        // REGISTRAR EN AUDITORÍA
        $detalles_audit = "Ticket #$id_venta | Monto devuelto: $" . $monto . " | Motivo: " . $motivo_dev;
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'DEVOLUCION', ?, NOW())")->execute([$user_id, $detalles_audit]);

        $conexion->commit();
        echo "<script>window.location.href='devoluciones.php?id_ticket=$id_venta&msg=".urlencode($msg)."&tipo=success';</script>"; exit;
    } catch (Exception $e) {
        if ($conexion->inTransaction()) $conexion->rollBack();
        echo "<script>window.location.href='devoluciones.php?id_ticket=$id_venta&msg=".urlencode($e->getMessage())."&tipo=error';</script>"; exit;
    }
}

// FILTROS DESDE / HASTA
$desde = $_GET['desde'] ?? date('Y-m-01');
$hasta = $_GET['hasta'] ?? date('Y-m-t');

$venta = null; $items = []; $productos_devueltos = [];
$id_ticket_buscado = $_GET['id_ticket'] ?? '';

if ($id_ticket_buscado > 0) {
    $stmt = $conexion->prepare("SELECT v.*, c.nombre as cliente FROM ventas v LEFT JOIN clientes c ON v.id_cliente = c.id WHERE v.id = ?");
    $stmt->execute([$id_ticket_buscado]);
    $venta = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($venta) {
        $stmtDet = $conexion->prepare("SELECT d.*, p.descripcion FROM detalle_ventas d JOIN productos p ON d.id_producto = p.id WHERE d.id_venta = ?");
        $stmtDet->execute([$id_ticket_buscado]);
        $items = $stmtDet->fetchAll(PDO::FETCH_ASSOC);
        $productos_devueltos = $conexion->prepare("SELECT id_producto FROM devoluciones WHERE id_venta_original = ?");
        $productos_devueltos->execute([$id_ticket_buscado]);
        $productos_devueltos = $productos_devueltos->fetchAll(PDO::FETCH_COLUMN);
    }
}

$sql = "SELECT v.id, v.total, v.fecha, c.nombre, 1 as tiene_dev 
        FROM ventas v 
        JOIN devoluciones d ON v.id = d.id_venta_original 
        LEFT JOIN clientes c ON v.id_cliente = c.id 
        WHERE DATE(d.fecha) >= ? AND DATE(d.fecha) <= ? 
        GROUP BY v.id 
        ORDER BY d.fecha DESC";
$stmtUltimas = $conexion->prepare($sql);
$stmtUltimas->execute([$desde, $hasta]);
$ultimas = $stmtUltimas->fetchAll(PDO::FETCH_ASSOC);

$devsHoy = $conexion->query("SELECT COUNT(*) FROM devoluciones WHERE DATE(fecha) = CURDATE()")->fetchColumn();
$plataHoy = $conexion->query("SELECT COALESCE(SUM(monto_devuelto),0) FROM devoluciones WHERE DATE(fecha) = CURDATE()")->fetchColumn();
?>

<?php include 'includes/layout_header.php'; ?>

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden; z-index: 10;">
    <i class="bi bi-arrow-counterclockwise bg-icon-large" style="z-index: 0;"></i>
    <div class="container position-relative" style="z-index: 2;">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Gestión de Devoluciones</h2>
                <p class="opacity-75 mb-0 text-white small">Control de reintegros y stock</p>
            </div>
            <?php if($es_admin || in_array('reporte_devoluciones', $permisos)): ?>
            <a href="reporte_devoluciones.php?desde=<?php echo $desde; ?>&hasta=<?php echo $hasta; ?>" target="_blank" class="btn btn-danger fw-bold rounded-pill px-4 shadow-sm">
                <i class="bi bi-file-earmark-pdf-fill me-2"></i> PDF
            </a>
            <?php endif; ?>
        </div>

        <div class="bg-white bg-opacity-10 p-3 rounded-4 shadow-sm d-inline-block border border-white border-opacity-25 mt-2 mb-4">
            <form method="GET" class="d-flex align-items-center gap-3 mb-0">
                <div class="d-flex align-items-center">
                    <span class="small fw-bold text-white text-uppercase me-2">Desde:</span>
                    <input type="date" name="desde" class="form-control border-0 shadow-sm rounded-3 fw-bold" value="<?php echo $desde; ?>" required style="max-width: 150px;">
                </div>
                <div class="d-flex align-items-center">
                    <span class="small fw-bold text-white text-uppercase me-2">Hasta:</span>
                    <input type="date" name="hasta" class="form-control border-0 shadow-sm rounded-3 fw-bold" value="<?php echo $hasta; ?>" required style="max-width: 150px;">
                </div>
                <button type="submit" class="btn btn-primary rounded-pill px-4 fw-bold shadow"><i class="bi bi-search me-2"></i> FILTRAR</button>
            </form>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div><div class="widget-label">Devoluciones Filtradas</div><div class="widget-value text-white"><?php echo count($ultimas); ?> Tickets</div></div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-receipt"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div><div class="widget-label">Reintegros Hoy</div><div class="widget-value text-white"><?php echo $devsHoy; ?> ítems</div></div>
                    <div class="icon-box bg-danger bg-opacity-20 text-white"><i class="bi bi-arrow-return-left"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div><div class="widget-label">Monto Devuelto Hoy</div><div class="widget-value text-white">$<?php echo number_format($plataHoy, 0, ',', '.'); ?></div></div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-currency-dollar"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5 mt-4">
    <div class="row g-4">
        <div class="col-md-4">
            <div class="card card-custom border-0 shadow-sm mb-4">
                <div class="card-header bg-white py-3"><h6 class="fw-bold mb-0">Buscar Ticket Específico</h6></div>
                <div class="card-body">
                    <form method="GET" class="d-flex gap-2">
                        <input type="hidden" name="desde" value="<?php echo $desde; ?>">
                        <input type="hidden" name="hasta" value="<?php echo $hasta; ?>">
                        <input type="number" name="id_ticket" class="form-control fw-bold" placeholder="N°" required value="<?php echo htmlspecialchars($id_ticket_buscado); ?>">
                        <button class="btn btn-primary px-3"><i class="bi bi-search"></i></button>
                    </form>
                </div>
            </div>

            <div class="card card-custom border-0 shadow-sm">
                <div class="card-header bg-white py-3"><h6 class="fw-bold mb-0">Tickets Filtrados (Con Devolución)</h6></div>
                <div class="list-group list-group-flush" style="max-height: 400px; overflow-y: auto;">
                    <?php if(empty($ultimas)): ?>
                        <div class="text-center py-4 text-muted small">No hay tickets con devoluciones.</div>
                    <?php endif; ?>
                    <?php foreach($ultimas as $u): ?>
                        <a href="devoluciones.php?desde=<?php echo $desde; ?>&hasta=<?php echo $hasta; ?>&id_ticket=<?php echo $u['id']; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-3">
                            <div>
                                <span class="fw-bold text-dark">#<?php echo $u['id']; ?></span>
                                <small class="d-block text-muted"><?php echo substr($u['nombre'] ?? 'Consumidor Final', 0, 18); ?></small>
                            </div>
                            <div class="text-end">
                                <span class="fw-bold text-dark">$<?php echo number_format($u['total'], 0); ?></span>
                                <span class="badge bg-warning text-dark d-block mt-1" style="font-size: 0.6rem;">CON DEV</span>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <?php if($venta): ?>
                <div class="card card-custom border-0 shadow-sm">
                    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
                        <h5 class="fw-bold mb-0">Ticket #<?php echo $venta['id']; ?></h5>
                        <span class="badge bg-light text-dark border"><?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?></span>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead><tr><th>Producto</th><th class="text-center">Cant.</th><th class="text-end">Subtotal</th><th class="text-center">Acción</th></tr></thead>
                                <tbody>
                                    <?php foreach($items as $i): 
                                        $ya_devuelto = in_array($i['id_producto'], $productos_devueltos);
                                    ?>
                                    <tr class="<?php echo $ya_devuelto ? 'opacity-50 bg-light' : ''; ?>">
                                        <td><?php echo $i['descripcion']; ?></td>
                                        <td class="text-center fw-bold"><?php echo floatval($i['cantidad']); ?></td>
                                        <td class="text-end fw-bold">$<?php echo number_format($i['subtotal'], 2); ?></td>
                                        <td class="text-center">
                                            <?php if($ya_devuelto): ?>
                                                <span class="badge bg-danger">DEVUELTO</span>
                                            <?php else: ?>
                                                <form method="POST" class="d-inline">
                                                    <input type="hidden" name="devolver" value="1">
                                                    <input type="hidden" name="id_venta" value="<?php echo $venta['id']; ?>">
                                                    <input type="hidden" name="id_producto" value="<?php echo $i['id_producto']; ?>">
                                                    <input type="hidden" name="cantidad" value="<?php echo $i['cantidad']; ?>">
                                                    <input type="hidden" name="monto" value="<?php echo $i['subtotal']; ?>">
                                                    <button type="button" class="btn btn-sm btn-outline-danger btn-confirmar-dev"><i class="bi bi-arrow-return-left"></i> DEVOLVER</button>
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
                <div class="text-center py-5">
                    <i class="bi bi-receipt display-1 text-light"></i>
                    <p class="text-muted mt-3">Seleccioná o buscá un ticket para gestionar devoluciones</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    <?php if(isset($_GET['msg'])): ?>
        Swal.fire({ title: 'Aviso', text: <?php echo json_encode(urldecode($_GET['msg'])); ?>, icon: "<?php echo $_GET['tipo'] ?? 'info'; ?>", confirmButtonColor: '<?php echo $color_sistema; ?>' });
    <?php endif; ?>

    document.querySelectorAll('.btn-confirmar-dev').forEach(btn => {
        btn.addEventListener('click', function() {
            let form = this.closest('form');
            Swal.fire({
                title: '¿Confirmar Devolución?',
                html: `
                    <div class="text-start mb-3">Se reintegrará el dinero. ¿Qué pasó con el producto?</div>
                    <select id="motivo_dev_swal" class="form-select">
                        <option value="Reingreso">✅ Vuelve al Stock (Intacto)</option>
                        <option value="Roto/Defectuoso">❌ Estaba Roto/Defectuoso (Merma)</option>
                        <option value="Vencido">📅 Estaba Vencido (Merma)</option>
                    </select>
                `,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc3545',
                confirmButtonText: 'Sí, confirmar',
                preConfirm: () => {
                    const motivo = document.getElementById('motivo_dev_swal').value;
                    const inputMotivo = document.createElement('input');
                    inputMotivo.type = 'hidden';
                    inputMotivo.name = 'motivo_dev';
                    inputMotivo.value = motivo;
                    form.appendChild(inputMotivo);
                }
            }).then((result) => { if (result.isConfirmed) form.submit(); });
        });
    });
</script>
<?php include 'includes/layout_footer.php'; ?>