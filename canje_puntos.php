<?php
// canje_puntos.php - VERSIÓN BLINDADA Y DETALLADA
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. CONEXIÓN
$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// Buscamos la última caja abierta en el sistema
$stmtCaja = $conexion->query("SELECT id FROM cajas_sesion WHERE estado = 'abierta' ORDER BY id DESC LIMIT 1");
$caja = $stmtCaja->fetch(PDO::FETCH_ASSOC);
$id_caja_sesion = $caja ? $caja['id'] : 0;

$mensaje_sweet = '';
$resultados_busqueda = [];
$cliente_seleccionado = null;

// --- NUEVA LÓGICA: GUARDAR REGLA DE PUNTOS ---
if (isset($_POST['guardar_regla'])) {
    $monto_base = floatval($_POST['monto_base']);
    $puntos_otorgados = floatval($_POST['puntos_otorgados']);
    
    if ($monto_base > 0 && $puntos_otorgados > 0) {
        $ratio = $monto_base / $puntos_otorgados;
        $sqlRegla = "UPDATE configuracion SET dinero_por_punto = ? WHERE id = 1";
        $conexion->prepare($sqlRegla)->execute([$ratio]);

        // AUDITORÍA: CAMBIO REGLA DE PUNTOS
        try {
            $detalles_audit = "Cambio en regla de puntos: $" . number_format($monto_base, 2) . " = " . $puntos_otorgados . " pts. (Ratio: $" . number_format($ratio, 2) . " por punto)";
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'CONFIG_PUNTOS', ?, NOW())")
                     ->execute([$_SESSION['usuario_id'], $detalles_audit]);
        } catch (Exception $e) { }

        $mensaje_sweet = "Swal.fire('Configuración Guardada', 'Ahora cada $$monto_base los clientes sumarán $puntos_otorgados puntos.', 'success');";
    }
}

// --- DATOS PARA WIDGETS ---
$stmtW1 = $conexion->query("SELECT COUNT(*) FROM premios WHERE activo = 1");
$totalPremios = $stmtW1->fetchColumn();

$stmtW2 = $conexion->query("SELECT COUNT(*) FROM auditoria WHERE accion = 'CANJE' AND DATE(fecha) = CURDATE()");
$canjesHoy = $stmtW2->fetchColumn();

$stmtW3 = $conexion->query("SELECT SUM(puntos_acumulados) FROM clientes");
$puntosTotales = $stmtW3->fetchColumn() ?: 0;

$topClientes = $conexion->query("SELECT * FROM clientes WHERE puntos_acumulados > 0 ORDER BY puntos_acumulados DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

// OBTENER CONFIGURACIÓN ACTUAL
$conf = $conexion->query("SELECT dinero_por_punto FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$ratio_actual = $conf['dinero_por_punto'] ?? 100;

// 2. LÓGICA DE BÚSQUEDA
if (isset($_GET['q']) && !empty($_GET['q'])) {
    $q = trim($_GET['q']);
    $term = "%$q%";
    $sql = "SELECT * FROM clientes WHERE nombre LIKE ? OR dni LIKE ? OR dni_cuit LIKE ? OR id = ? LIMIT 20";
    $stmt = $conexion->prepare($sql);
    $stmt->execute([$term, $term, $term, $q]);
    $resultados_busqueda = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 3. SELECCIÓN DE CLIENTE
if (isset($_GET['id_cliente'])) {
    $stmt = $conexion->prepare("SELECT * FROM clientes WHERE id = ?");
    $stmt->execute([$_GET['id_cliente']]);
    $cliente_seleccionado = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 4. PROCESAR CANJE (LÓGICA CORREGIDA PARA DETALLES)
if (isset($_POST['canjear']) && $cliente_seleccionado) {
    $id_cliente = $_POST['id_cliente'];
    $id_premio = $_POST['id_premio'];
    
    try {
        $conexion->beginTransaction();
        
        $stmtC = $conexion->prepare("SELECT puntos_acumulados FROM clientes WHERE id = ?");
        $stmtC->execute([$id_cliente]);
        $pts_actuales = $stmtC->fetchColumn();
        
        $stmtP = $conexion->prepare("SELECT * FROM premios WHERE id = ?");
        $stmtP->execute([$id_premio]);
        $premio = $stmtP->fetch(PDO::FETCH_ASSOC);
        
        if ($pts_actuales >= $premio['puntos_necesarios']) {
            // 1. Descontar Puntos
            $nuevo_saldo = $pts_actuales - $premio['puntos_necesarios'];
            $conexion->prepare("UPDATE clientes SET puntos_acumulados = ? WHERE id = ?")->execute([$nuevo_saldo, $id_cliente]);
            
            $txt_log = "";
            $detalle_receta = ""; // Variable clave para el detalle

            // 2. Procesar Cupón o Producto
            if ($premio['es_cupon'] == 1) {
                $monto = $premio['monto_dinero'];
                $conexion->prepare("UPDATE clientes SET saldo_favor = saldo_favor + ? WHERE id = ?")->execute([$monto, $id_cliente]);
                $txt_log = "Canje Cupón $$monto";
            } else {
                $costo_gasto = 0; 
                
                // A. PRODUCTO INDIVIDUAL
                if ($premio['tipo_articulo'] == 'producto' && !empty($premio['id_articulo'])) {
                    $conexion->prepare("UPDATE productos SET stock_actual = stock_actual - 1 WHERE id = ?")->execute([$premio['id_articulo']]);
                    
                    // Obtenemos costo y nombre para el detalle
                    $stmtProd = $conexion->prepare("SELECT precio_costo, descripcion FROM productos WHERE id = ?");
                    $stmtProd->execute([$premio['id_articulo']]);
                    $prodData = $stmtProd->fetch(PDO::FETCH_ASSOC);
                    
                    $costo_gasto = $prodData['precio_costo'];
                    $detalle_receta = " (Producto: " . $prodData['descripcion'] . ")";

                } 
                // B. COMBO (Aquí estaba el problema del detalle)
                elseif ($premio['tipo_articulo'] == 'combo' && !empty($premio['id_articulo'])) {
                    $stmtItems = $conexion->prepare("SELECT ci.id_producto, ci.cantidad, p.precio_costo, p.descripcion 
                                                     FROM combo_items ci 
                                                     JOIN productos p ON ci.id_producto = p.id 
                                                     WHERE ci.id_combo = ?");
                    $stmtItems->execute([$premio['id_articulo']]);
                    $items_combo = $stmtItems->fetchAll(PDO::FETCH_ASSOC);

                    $detalle_receta = " (Incluye: ";
                    foreach($items_combo as $item) {
                        $conexion->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?")
                                 ->execute([$item['cantidad'], $item['id_producto']]);
                        
                        $costo_gasto += ($item['precio_costo'] * $item['cantidad']);
                        $detalle_receta .= $item['descripcion'] . " x" . floatval($item['cantidad']) . ", ";
                    }
                    $detalle_receta = rtrim($detalle_receta, ", ") . ")";
                }

                $txt_log = "Canje Producto: " . $premio['nombre'];

                // REGISTRAR GASTO (Ahora incluye $detalle_receta)
                if ($costo_gasto > 0 && $id_caja_sesion > 0) {
                    $desc_gasto = "Costo Canje Fidelización: " . $premio['nombre'] . $detalle_receta . " | Cliente: " . $cliente_seleccionado['nombre'];
                    $conexion->prepare("INSERT INTO gastos (descripcion, monto, categoria, fecha, id_usuario, id_caja_sesion) VALUES (?, ?, 'Fidelizacion', NOW(), ?, ?)")
                             ->execute([$desc_gasto, $costo_gasto, $_SESSION['usuario_id'], $id_caja_sesion]);
                }

                $conexion->prepare("UPDATE premios SET stock = stock - 1 WHERE id = ?")->execute([$id_premio]);
            }
            
            // 3. REGISTRAR AUDITORÍA (CORREGIDO: Ahora incluye el detalle completo)
            $detalle_audit = $txt_log . $detalle_receta . " (-" . $premio['puntos_necesarios'] . " pts)";
            
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'CANJE', ?, NOW())")
                     ->execute([$_SESSION['usuario_id'], $detalle_audit]);
            
            $conexion->commit();
            header("Location: canje_puntos.php?id_cliente=$id_cliente&exito=1");
            exit;
        } else { throw new Exception("Puntos insuficientes."); }
    } catch (Exception $e) {
        if($conexion->inTransaction()) $conexion->rollBack();
        $mensaje_sweet = "Swal.fire('Error', '".$e->getMessage()."', 'error');";
    }
}

$premios = $conexion->query("SELECT * FROM premios WHERE activo = 1 ORDER BY puntos_necesarios ASC")->fetchAll(PDO::FETCH_ASSOC);
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
    <i class="bi bi-gift-fill bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Centro de Fidelización</h2>
                <p class="opacity-75 mb-0 text-white small">Configurá y gestioná las recompensas de tus clientes.</p>
            </div>
            <?php if(!$cliente_seleccionado): ?>
                <button class="btn btn-warning fw-bold rounded-pill shadow" data-bs-toggle="modal" data-bs-target="#modalRegla">
                    <i class="bi bi-gear-fill me-2"></i> REGLA DE PUNTOS
                </button>
            <?php else: ?>
                <a href="canje_puntos.php" class="btn btn-outline-light rounded-pill fw-bold">
                    <i class="bi bi-arrow-left me-2"></i> VOLVER
                </a>
            <?php endif; ?>
        </div>

        <div class="row g-3">
            <div class="col-6 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Premios</div>
                        <div class="widget-value text-white"><?php echo $totalPremios; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white">
                        <i class="bi bi-award"></i>
                    </div>
                </div>
            </div>

            <div class="col-6 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Canjes Hoy</div>
                        <div class="widget-value text-white"><?php echo $canjesHoy; ?></div>
                    </div>
                    <div class="icon-box bg-success bg-opacity-20 text-white">
                        <i class="bi bi-check-circle"></i>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="header-widget border-info">
                    <div>
                        <div class="widget-label">Valor de 1 Punto</div>
                        <div class="widget-value text-white">$<?php echo number_format($ratio_actual, 2); ?></div>
                    </div>
                    <div class="icon-box bg-info bg-opacity-20 text-white">
                        <i class="bi bi-currency-dollar"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="container pb-5">
    <?php if (!$cliente_seleccionado): ?>
        <div class="row g-4">
            <div class="col-lg-6">
                <div class="card border-0 shadow-lg p-4 rounded-4 h-100">
                    <div class="text-center mb-4">
                        <i class="bi bi-person-search display-4 text-primary"></i>
                        <h4 class="fw-bold">Buscar Cliente</h4>
                    </div>
                    <form method="GET">
                        <div class="input-group input-group-lg">
                            <input type="text" name="q" class="form-control" placeholder="Nombre o DNI..." value="<?php echo $_GET['q'] ?? ''; ?>" autofocus>
                            <button class="btn btn-primary px-4"><i class="bi bi-search"></i></button>
                        </div>
                    </form>

                    <?php if (!empty($resultados_busqueda)): ?>
                        <div class="list-group mt-4">
                            <?php foreach ($resultados_busqueda as $cli): ?>
                                <a href="canje_puntos.php?id_cliente=<?php echo $cli['id']; ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                                    <div><div class="fw-bold"><?php echo $cli['nombre']; ?></div><small>DNI: <?php echo $cli['dni']; ?></small></div>
                                    <span class="badge bg-warning text-dark rounded-pill"><?php echo $cli['puntos_acumulados']; ?> pts</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card border-0 shadow-sm h-100 rounded-4">
                    <div class="card-header bg-white py-3 fw-bold"><i class="bi bi-trophy text-warning me-2"></i> Ranking de Puntos</div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <tbody>
                                <?php foreach($topClientes as $tc): ?>
                                    <tr onclick="window.location.href='canje_puntos.php?id_cliente=<?php echo $tc['id']; ?>'" style="cursor:pointer">
                                        <td class="ps-4"><b><?php echo $tc['nombre']; ?></b></td>
                                        <td class="text-end pe-4"><span class="badge bg-warning text-dark"><?php echo $tc['puntos_acumulados']; ?> pts</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-4">
            <div class="col-lg-4">
                <div class="card border-0 shadow rounded-4 overflow-hidden">
                    <div class="client-card-header text-center pb-5">
                        <i class="bi bi-person-circle display-1"></i>
                        <h4 class="fw-bold"><?php echo $cliente_seleccionado['nombre']; ?></h4>
                    </div>
                    <div class="card-body text-center" style="margin-top: -40px;">
                        <div class="card border-0 shadow-sm mb-4 rounded-4">
                            <div class="card-body py-4">
                                <small class="fw-bold text-muted">PUNTOS DISPONIBLES</small>
                                <div class="display-4 fw-bold text-warning"><?php echo number_format($cliente_seleccionado['puntos_acumulados']); ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-lg-8">
                <div class="row row-cols-1 row-cols-md-3 g-3">
                    <?php foreach($premios as $p): 
                        $alcanza = $cliente_seleccionado['puntos_acumulados'] >= $p['puntos_necesarios'];
                    ?>
                    <div class="col">
                        <div class="card prize-card h-100 <?php echo $alcanza ? 'border-success' : 'opacity-50 grayscale'; ?>">
                            <div class="card-body text-center d-flex flex-column">
                                <i class="bi <?php echo $p['es_cupon'] ? 'bi-ticket-perforated' : 'bi-gift'; ?> display-5 text-primary mb-3"></i>
                                <h6 class="fw-bold"><?php echo $p['nombre']; ?></h6>
                                <h4 class="fw-bold text-success mt-auto"><?php echo $p['puntos_necesarios']; ?> pts</h4>
                                <?php if($alcanza): ?>
                                    <button onclick="canjear(<?php echo $p['id']; ?>, '<?php echo $p['nombre']; ?>', <?php echo $p['puntos_necesarios']; ?>)" class="btn btn-success rounded-pill fw-bold mt-3">CANJEAR</button>
                                <?php else: ?>
                                    <span class="badge bg-danger mt-3">Te faltan <?php echo $p['puntos_necesarios'] - $cliente_seleccionado['puntos_acumulados']; ?> pts</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<div class="modal fade" id="modalRegla" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <form method="POST" class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-dark text-white">
                <h5 class="modal-title fw-bold"><i class="bi bi-stars"></i> Regla de Acumulación</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p class="text-muted small mb-4">Define cuántos puntos sumará el cliente según el monto de su compra.</p>
                <div class="row g-3 align-items-center">
                    <div class="col-6">
                        <label class="form-label fw-bold small text-muted">CADA COMPRA DE ($)</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" name="monto_base" class="form-control fw-bold" value="1000" required>
                        </div>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold small text-muted">SUMARÁ (PUNTOS)</label>
                        <div class="input-group">
                            <input type="number" name="puntos_otorgados" class="form-control fw-bold text-center" value="5" required>
                            <span class="input-group-text">PTS</span>
                        </div>
                    </div>
                </div>
                <div class="alert alert-info mt-4 mb-0 border-0 rounded-4 small">
                    <i class="bi bi-info-circle-fill me-2"></i>
                    <b>Info:</b> Si pones $1000 y 5 puntos, el sistema le dará 1 punto cada $200 gastados automáticamente.
                </div>
            </div>
            <div class="modal-footer bg-light">
                <button type="submit" name="guardar_regla" class="btn btn-primary w-100 fw-bold py-2 rounded-pill">GUARDAR NUEVA REGLA</button>
            </div>
        </form>
    </div>
</div>

<form id="formCanje" method="POST">
    <input type="hidden" name="canjear" value="1">
    <input type="hidden" name="id_cliente" value="<?php echo $cliente_seleccionado['id'] ?? ''; ?>">
    <input type="hidden" name="id_premio" id="p_id">
</form>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    <?php echo $mensaje_sweet; ?>
    if(new URLSearchParams(window.location.search).get('exito') === '1') {
        Swal.fire('¡Canje Exitoso!', 'Los puntos han sido descontados y el premio asignado.', 'success');
    }
    function canjear(id, nom, pts) {
        Swal.fire({
            title: '¿Confirmar Canje?',
            text: `Vas a canjear ${pts} puntos por: ${nom}`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Sí, Canjear',
            cancelButtonText: 'Cancelar'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('p_id').value = id;
                document.getElementById('formCanje').submit();
            }
        });
    }
</script>

<?php include 'includes/layout_footer.php'; ?>