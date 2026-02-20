<?php
// gastos.php - VERSIÓN FINAL CON TICKET VISUAL Y REPORTE PDF
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// 1. CONEXIÓN
$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

// 2. SEGURIDAD
$permisos = $_SESSION['permisos'] ?? [];
$rol = $_SESSION['rol'] ?? 3;
if (!in_array('gestionar_gastos', $permisos) && $rol > 2) { header("Location: dashboard.php"); exit; }

// 3. VERIFICAR CAJA (Modificado para permitir visualización sin apertura)
$usuario_id = $_SESSION['usuario_id'];
$stmt = $conexion->prepare("SELECT id FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta'");
$stmt->execute([$usuario_id]);
$caja = $stmt->fetch(PDO::FETCH_ASSOC);

$id_caja_sesion = $caja['id'] ?? null;

// 4. PROCESAR GASTO
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!$id_caja_sesion) {
        die("Error: Debes tener una caja abierta para registrar una salida de dinero.");
    }
    $desc = $_POST['descripcion'];
    $monto = $_POST['monto'];
    $cat = $_POST['categoria'];
    
    $stmt = $conexion->prepare("INSERT INTO gastos (descripcion, monto, categoria, fecha, id_usuario, id_caja_sesion) VALUES (?, ?, ?, NOW(), ?, ?)");
    $stmt->execute([$desc, $monto, $cat, $_SESSION['usuario_id'], $id_caja_sesion]);
    $id_gasto_nuevo = $conexion->lastInsertId();

    // AUDITORÍA: REGISTRO DE GASTO
    try {
        $detalles_audit = "Gasto registrado (#" . $id_gasto_nuevo . ") en categoría '" . $cat . "' por $" . number_format($monto, 2) . ". Detalle: " . $desc;
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'GASTO', ?, NOW())")
                 ->execute([$_SESSION['usuario_id'], $detalles_audit]);
    } catch (Exception $e) { }

    header("Location: gastos.php?msg=ok"); exit;
}

// 5. DATOS
$gastos = $conexion->query("SELECT g.*, u.usuario FROM gastos g JOIN usuarios u ON g.id_usuario = u.id ORDER BY g.fecha DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

// --- PROCESAMIENTO INTELIGENTE DE DETALLES ---
foreach ($gastos as &$g) {
    // Variables por defecto para el JS
    $g['info_extra_titulo'] = '';   // Ej: BENEFICIARIO, CLIENTE, PROVEEDOR
    $g['info_extra_nombre'] = '';   // Ej: Juan Perez
    $g['lista_items_titulo'] = '';  // Ej: DETALLE RECETA, PRODUCTOS DEVUELTOS
    $g['lista_items'] = [];         // Array con {cantidad, descripcion, monto}

    // CASO A: FIDELIZACIÓN (Canje de Puntos)
    if (($g['categoria'] == 'Fidelizacion' || $g['categoria'] == 'Fidelización') && preg_match('/Cliente #(\d+)/', $g['descripcion'], $matches)) {
        $idCliente = $matches[1];
        
        // 1. Buscar Nombre Cliente
        $stmt = $conexion->prepare("SELECT nombre FROM clientes WHERE id = ?");
        $stmt->execute([$idCliente]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $g['info_extra_titulo'] = 'BENEFICIARIO';
            $g['info_extra_nombre'] = $row['nombre'];
        }

        // 2. Buscar Receta del Combo
        if (preg_match('/:\s(.*?)\s\(/', $g['descripcion'], $matchPremio)) {
            $nombrePremio = $matchPremio[1]; 
            $stmtP = $conexion->prepare("SELECT id_articulo, tipo_articulo FROM premios WHERE nombre = ?");
            $stmtP->execute([$nombrePremio]);
            $premio = $stmtP->fetch(PDO::FETCH_ASSOC);

            if ($premio && $premio['tipo_articulo'] == 'combo') {
                $g['lista_items_titulo'] = 'COSTO RECETA (COMBO)';
                $stmtI = $conexion->prepare("SELECT p.descripcion, ci.cantidad, p.precio_costo as monto FROM combo_items ci JOIN productos p ON ci.id_producto = p.id WHERE ci.id_combo = ?");
                $stmtI->execute([$premio['id_articulo']]);
                $g['lista_items'] = $stmtI->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }

    // CASO B: DEVOLUCIONES (Reintegro de Dinero)
    elseif ($g['categoria'] == 'Devoluciones' && preg_match('/Ticket #(\d+)/', $g['descripcion'], $matches)) {
        $idTicket = $matches[1];

        // 1. Buscar Cliente Original en la Venta
        $stmt = $conexion->prepare("SELECT c.nombre FROM ventas v JOIN clientes c ON v.id_cliente = c.id WHERE v.id = ?");
        $stmt->execute([$idTicket]);
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $g['info_extra_titulo'] = 'CLIENTE ORIG.';
            $g['info_extra_nombre'] = $row['nombre'];
        }

        // 2. Buscar Qué productos devolvió
        $g['lista_items_titulo'] = 'ÍTEMS DEVUELTOS';
        $stmtD = $conexion->prepare("SELECT p.descripcion, d.cantidad, d.monto_devuelto as monto FROM devoluciones d JOIN productos p ON d.id_producto = p.id WHERE d.id_venta_original = ?");
        $stmtD->execute([$idTicket]);
        $g['lista_items'] = $stmtD->fetchAll(PDO::FETCH_ASSOC);
    }
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
unset($g);
// --- FIN MODIFICACIÓN ---
// KPIS
$hoy = date('Y-m-d');
$totalHoy = $conexion->query("SELECT SUM(monto) FROM gastos WHERE DATE(fecha) = '$hoy'")->fetchColumn() ?: 0;
$cantHoy = $conexion->query("SELECT COUNT(*) FROM gastos WHERE DATE(fecha) = '$hoy'")->fetchColumn() ?: 0;
$mesActual = date('Y-m');
$totalMes = $conexion->query("SELECT SUM(monto) FROM gastos WHERE DATE_FORMAT(fecha, '%Y-%m') = '$mesActual'")->fetchColumn() ?: 0;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Control de Gastos</title>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
</head>
<body class="bg-light">
    
    <?php include 'includes/layout_header.php'; ?>
    </div>

    <div class="header-blue" style="background-color: <?php echo $color_sistema; ?> !important;">
    <i class="bi bi-wallet2 bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Gastos y Retiros</h2>
                <p class="opacity-75 mb-0 text-white small">Control de movimientos operativos.</p>
            </div>
            <a href="reporte_gastos.php" target="_blank" class="btn btn-light text-primary fw-bold rounded-pill px-4 shadow-sm">
                <i class="bi bi-file-earmark-pdf-fill me-2"></i> REPORTE PDF
            </a>
        </div>

        <div class="row g-3">
            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Salidas Hoy</div>
                        <div class="widget-value text-white">$<?php echo number_format($totalHoy, 0, ',', '.'); ?></div>
                    </div>
                    <div class="icon-box bg-danger bg-opacity-20 text-white"><i class="bi bi-cash-stack"></i></div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Movimientos</div>
                        <div class="widget-value text-white"><?php echo $cantHoy; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-list-check"></i></div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Acumulado Mes</div>
                        <div class="widget-value text-white">$<?php echo number_format($totalMes, 0, ',', '.'); ?></div>
                    </div>
                    <div class="icon-box bg-warning bg-opacity-20 text-white"><i class="bi bi-calendar-check"></i></div>
                </div>
            </div>

            <div class="col-6 col-md-3">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Estado Caja</div>
                        <div class="widget-value text-white" style="font-size: 1.1rem;"><?php echo $id_caja_sesion ? 'OPERATIVA' : 'LECTURA'; ?></div>
                    </div>
                    <div class="icon-box bg-info bg-opacity-20 text-white"><i class="bi bi-key"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

    <div class="container pb-5">
        <div class="row g-4">
            
            <div class="col-md-4">
                <div class="card card-custom h-100">
                    <div class="card-header bg-white fw-bold py-3 border-bottom-0 text-danger">
                        <i class="bi bi-dash-circle-fill me-2"></i> Nuevo Retiro
                    </div>
                    <div class="card-body bg-light rounded-bottom">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="small fw-bold text-muted text-uppercase">Monto ($)</label>
                                <div class="input-group">
                                    <span class="input-group-text bg-white border-end-0 text-danger fw-bold">$</span>
                                    <input type="number" step="0.01" name="monto" class="form-control form-control-lg fw-bold border-start-0 text-danger" required placeholder="0.00">
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="small fw-bold text-muted text-uppercase">Descripción</label>
                                <input type="text" name="descripcion" class="form-control" required placeholder="Ej: Pago Proveedor">
                            </div>
                            <div class="mb-4">
                                <label class="small fw-bold text-muted text-uppercase">Categoría</label>
                                <select name="categoria" class="form-select">
                                    <option value="Proveedores">🚚 Proveedores</option>
                                    <option value="Servicios">💡 Servicios</option>
                                    <option value="Alquiler">🏠 Alquiler</option>
                                    <option value="Sueldos">👥 Sueldos</option>
                                    <option value="Retiro">💸 Retiro Ganancias</option>
                                    <option value="Insumos">🧻 Insumos</option>
                                    <option value="Otros">📦 Otros</option>
                                </select>
                            </div>
                            <button type="submit" class="btn btn-danger w-100 fw-bold py-2 shadow-sm">
                                <i class="bi bi-check-lg me-2"></i> REGISTRAR SALIDA
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card card-custom h-100">
                    <div class="card-header bg-white fw-bold py-3 border-bottom d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-clock-history me-2 text-secondary"></i> Últimos Movimientos</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light small text-uppercase text-muted">
                                <tr>
                                    <th class="ps-4 py-3">Fecha</th>
                                    <th>Detalle</th>
                                    <th>Categoría</th>
                                    <th class="text-end pe-4">Monto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($gastos) > 0): ?>
                                    <?php foreach($gastos as $g): 
                                        $icono = 'bi-box-seam';
                                        if($g['categoria'] == 'Proveedores') $icono = 'bi-truck';
                                        if($g['categoria'] == 'Servicios') $icono = 'bi-lightning-charge';
                                        if($g['categoria'] == 'Sueldos') $icono = 'bi-people';
                                        if($g['categoria'] == 'Retiro') $icono = 'bi-cash-stack';
                                        
                                        // Datos JSON para el ticket
                                        $jsonData = htmlspecialchars(json_encode($g), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr class="fila-gasto" onclick="verTicket(<?php echo $jsonData; ?>)">
                                        <td class="ps-4 text-muted small">
                                            <?php echo date('d/m H:i', strtotime($g['fecha'])); ?>
                                        </td>
                                        <td>
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($g['descripcion']); ?></div>
                                            <small class="text-muted"><i class="bi bi-person-circle me-1"></i> <?php echo htmlspecialchars($g['usuario']); ?></small>
                                        </td>
                                        <td>
                                            <span class="badge bg-light text-dark border badge-cat">
                                                <i class="bi <?php echo $icono; ?> me-1"></i> <?php echo $g['categoria']; ?>
                                            </span>
                                        </td>
                                        <td class="text-end text-danger fw-bold pe-4">
                                            -$<?php echo number_format($g['monto'], 2, ',', '.'); ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted">No hay gastos recientes.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include 'includes/layout_footer.php'; ?>

    <script>
    if(new URLSearchParams(window.location.search).get('msg') === 'ok') {
        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Gasto registrado', showConfirmButton: false, timer: 3000 });
    }

    function verTicket(gasto) {
        // Formatos Moneda y Fecha
        let montoF = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(gasto.monto);
        let fechaObj = new Date(gasto.fecha);
        let fechaF = fechaObj.toLocaleString('es-AR', { 
            timeZone: 'America/Argentina/Buenos_Aires',
            day: '2-digit', month: '2-digit', year: 'numeric', 
            hour: '2-digit', minute: '2-digit'
        });

        // 1. LIMPIEZA VISUAL DE LA DESCRIPCIÓN
        // Quitamos "(Cliente #XX)" y "Ticket #XX" para que no se repita con la info nueva
        let descLimpia = gasto.descripcion
            .replace(/\s*\(Cliente #\d+\)/, '') 
            .replace(/Ticket #\d+/, ''); 

        // 2. BLOQUE PERSONA (Beneficiario / Cliente)
        let htmlPersona = '';
        if (gasto.info_extra_nombre) {
            htmlPersona = `
                <div style="margin-bottom: 5px;">
                    <strong>${gasto.info_extra_titulo}:</strong> 
                    <span style="text-transform:uppercase;">${gasto.info_extra_nombre}</span>
                </div>`;
        }

        // 3. BLOQUE LISTA DE ITEMS (Receta o Devolución)
        let htmlItems = '';
        if (gasto.lista_items && gasto.lista_items.length > 0) {
            htmlItems += `<div style="margin-top:10px; border-top: 1px dotted #ccc; padding-top:5px;">`;
            htmlItems += `<small style="font-weight:bold; text-decoration:underline;">${gasto.lista_items_titulo}:</small><br>`;
            
            gasto.lista_items.forEach(item => {
                let montoItem = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(item.monto);
                htmlItems += `<div style="display:flex; justify-content:space-between; font-size:11px;">
                    <span>${parseFloat(item.cantidad)}x ${item.descripcion}</span>
                    <span>${montoItem}</span>
                </div>`;
            });
            htmlItems += `</div>`;
        }

        // ARMADO DEL TICKET
        Swal.fire({
            background: '#fff',
            width: 380,
            html: `
                <div style="font-family: 'Courier New', monospace; text-align: left; color: #000; font-size: 13px;">
                    <div style="text-align: center; border-bottom: 2px dashed #000; padding-bottom: 10px; margin-bottom: 15px;">
                        <h3 style="font-weight: bold; margin: 0;">COMPROBANTE GASTO</h3>
                        <small>ID OPERACIÓN: #${gasto.id}</small><br>
                        <small>${fechaF}</small>
                    </div>

                    <div style="margin-bottom: 5px;">
                        <strong>RESPONSABLE:</strong> ${gasto.usuario ? gasto.usuario.toUpperCase() : 'ADMIN'}
                    </div>
                    
                    ${htmlPersona}
                    
                    <div style="border-bottom: 1px dashed #ccc; margin: 10px 0;"></div>

                    <div style="margin-bottom: 10px;">
                        <strong>CATEGORÍA:</strong> ${gasto.categoria}<br>
                        <strong>DETALLE:</strong><br>
                        ${descLimpia}
                        ${htmlItems}
                    </div>

                    <div style="border-top: 2px dashed #000; margin-top: 15px; padding-top: 10px; display:flex; justify-content:space-between; align-items:center;">
                        <span style="font-size: 1.2em; font-weight:bold;">TOTAL:</span>
                        <span style="font-size: 1.4em; font-weight:bold; color: #dc3545;">-${montoF}</span>
                    </div>
                </div>
            `,
            showCloseButton: true,
            showConfirmButton: false
        });
    }
</script>
</body>
</html>