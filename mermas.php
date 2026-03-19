<?php
// mermas.php - VERSIÓN PREMIUM CON CANDADOS
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- CANDADOS DE SEGURIDAD ---
$permisos = $_SESSION['permisos'] ?? [];
$rol = $_SESSION['rol'] ?? 3;
$es_admin = ($rol <= 2);

// Candado: Acceso a la página
if (!$es_admin && !in_array('stock_registrar_merma', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

// CONFIGURACIÓN Y FIRMA
$conf = $conexion->query("SELECT nombre_negocio, direccion_local, cuit, logo_url, color_barra_nav, telefono_whatsapp FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf['color_barra_nav'] ?? '#102A57';

$usuario_id = $_SESSION['usuario_id'];
$ruta_firma = "img/firmas/firma_admin.png";
if (!file_exists($ruta_firma) && file_exists("img/firmas/usuario_{$usuario_id}.png")) {
    $ruta_firma = "img/firmas/usuario_{$usuario_id}.png";
}

$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

// PROCESAR BAJA
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['id_producto'])) {
    if (!$es_admin && !in_array('crear_merma', $permisos)) { die("Sin permiso para registrar mermas."); }
    $id_prod = $_POST['id_producto'];
    $cant = $_POST['cantidad'];
    $motivo = $_POST['motivo'];
    $nota = $_POST['nota_adicional'] ?? '';
    $motivo_full = $motivo . ($nota ? " ($nota)" : "");

    try {
        $conexion->beginTransaction();
        $stmt = $conexion->prepare("INSERT INTO mermas (id_producto, cantidad, motivo, fecha, id_usuario, tipo_negocio) VALUES (?, ?, ?, NOW(), ?, ?)");
        $stmt->execute([$id_prod, $cant, $motivo_full, $usuario_id, $rubro_actual]);
        
        $conexion->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?")->execute([$cant, $id_prod]);
        
        // NUEVO: REGISTRO OBLIGATORIO EN LA CAJA NEGRA (AUDITORÍA)
        $detalles_audit = "Baja de stock: " . floatval($cant) . " unid. | Motivo: " . $motivo_full;
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha, tipo_negocio) VALUES (?, 'MERMA', ?, NOW(), ?)")->execute([$usuario_id, $detalles_audit, $rubro_actual]);

        $conexion->commit();
        header("Location: mermas.php?msg=ok"); exit;
    } catch (Exception $e) { 
        if ($conexion->inTransaction()) $conexion->rollBack(); 
        die("Error: " . $e->getMessage()); 
    }
}

// FILTROS AVANZADOS
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-2 months'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$f_usu = $_GET['id_usuario'] ?? '';
$buscar = trim($_GET['buscar'] ?? '');

$cond = ["DATE(m.fecha) >= ?", "DATE(m.fecha) <= ?", "m.motivo NOT LIKE 'Devolución #%'", "(m.tipo_negocio = '$rubro_actual' OR m.tipo_negocio IS NULL)"];
$params = [$desde, $hasta];
if($f_usu !== '') { $cond[] = "m.id_usuario = ?"; $params[] = $f_usu; }
if(!empty($buscar)) { 
    $cond[] = "(p.descripcion LIKE ? OR m.motivo LIKE ? OR m.id = ?)"; 
    array_push($params, "%$buscar%", "%$buscar%", intval($buscar)); 
}

$productos = $conexion->query("SELECT id, descripcion, stock_actual FROM productos WHERE activo=1 AND (tipo_negocio = '$rubro_actual' OR tipo_negocio IS NULL) ORDER BY descripcion ASC")->fetchAll(PDO::FETCH_OBJ);

$stmtM = $conexion->prepare("SELECT m.*, p.descripcion, p.precio_costo, u.usuario, u.nombre_completo, r.nombre as nombre_rol, u.id as id_op 
                             FROM mermas m 
                             JOIN productos p ON m.id_producto = p.id 
                             JOIN usuarios u ON m.id_usuario = u.id 
                             JOIN roles r ON u.id_rol = r.id
                             WHERE " . implode(" AND ", $cond) . " 
                             ORDER BY m.fecha DESC");
$stmtM->execute($params);
$mermas = $stmtM->fetchAll(PDO::FETCH_OBJ);

$kpiHoy = $conexion->query("SELECT COUNT(*) as cant, COALESCE(SUM(m.cantidad * p.precio_costo), 0) as costo_total FROM mermas m JOIN productos p ON m.id_producto = p.id WHERE DATE(m.fecha) = CURDATE() AND (m.tipo_negocio = '$rubro_actual' OR m.tipo_negocio IS NULL)")->fetch(PDO::FETCH_ASSOC);

$montoFiltrado = 0;
foreach($mermas as $m) { $montoFiltrado += ($m->cantidad * $m->precio_costo); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Control de Mermas</title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        @media (max-width: 768px) {
            .tabla-movil-ajustada td, .tabla-movil-ajustada th { padding: 0.5rem 0.3rem !important; font-size: 0.75rem !important; }
            .tabla-movil-ajustada .fw-bold { font-size: 0.8rem !important; }
            .tabla-movil-ajustada small { font-size: 0.7rem !important; }
            .tabla-movil-ajustada .badge { font-size: 0.65rem !important; padding: 0.35em 0.5em !important; }
        }
    </style>
</head>
<body class="bg-light">
    
    <?php include 'includes/layout_header.php'; ?>

    <?php
// --- DEFINICIÓN DEL BANNER DINÁMICO ---
$titulo = "Bajas de Inventario";
$subtitulo = "Administración de pérdidas y ajustes de stock.";
$icono_bg = "bi-trash3";

$query_filtros = !empty($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : "desde=$desde&hasta=$hasta";
$botones = [];
if($es_admin || in_array('reporte_mermas', $permisos)) {
    $botones[] = ['texto' => 'Reporte PDF', 'link' => "reporte_mermas.php?$query_filtros", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-3 px-md-4 py-2 shadow-sm', 'target' => '_blank'];
}
$widgets = [
    ['label' => 'Bajas Filtradas', 'valor' => count($mermas), 'icono' => 'bi-box-seam', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Pérdida Filtrada', 'valor' => '$'.number_format($montoFiltrado, 0, ',', '.'), 'icono' => 'bi-graph-down-arrow', 'border' => 'border-danger', 'icon_bg' => 'bg-danger bg-opacity-20'],
    ['label' => 'Estado Stock', 'valor' => 'SINCRONIZADO', 'icono' => 'bi-shield-check', 'border' => 'border-info', 'icon_bg' => 'bg-info bg-opacity-20']
];

include 'includes/componente_banner.php'; 
?>

<style>
    @media (max-width: 768px) {
        .tabla-movil-ajustada td, .tabla-movil-ajustada th { padding: 0.4rem 0.2rem !important; font-size: 0.75rem !important; }
        .tabla-movil-ajustada .fw-bold { font-size: 0.8rem !important; }
    }
</style>

<div class="container-fluid container-md pb-5 mt-n4 px-2 px-md-3" style="position: relative; z-index: 20;">

    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border-left: 5px solid #ff9800 !important;">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="row g-2 align-items-center mb-0">
                <input type="hidden" name="desde" value="<?php echo htmlspecialchars($desde); ?>">
                <input type="hidden" name="hasta" value="<?php echo htmlspecialchars($hasta); ?>">
                <input type="hidden" name="id_usuario" value="<?php echo htmlspecialchars($f_usu); ?>">
                
                <div class="col-md-8 col-12 text-center text-md-start">
                    <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador Rápido</h6>
                    <p class="small mb-0 opacity-75 d-none d-md-block">Busque por nombre de producto, motivo o número de OP.</p>
                </div>
                <div class="col-md-4 col-12 text-end mt-2 mt-md-0">
                    <div class="input-group input-group-sm">
                        <input type="text" name="buscar" class="form-control border-0 fw-bold shadow-none" placeholder="Buscar baja..." value="<?php echo htmlspecialchars($buscar); ?>">
                        <button class="btn btn-dark px-3 shadow-none border-0" type="submit"><i class="bi bi-arrow-right-circle-fill"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="alert py-2 small mb-3 text-center fw-bold border-0 shadow-sm rounded-3" style="background-color: #e9f2ff; color: #102A57;">
        <i class="bi bi-hand-index-thumb-fill me-1"></i> Toca o haz clic en un registro para ver su comprobante
    </div>
    
    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-3">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100">
                <input type="hidden" name="buscar" value="<?php echo htmlspecialchars($buscar); ?>">
                <div class="flex-grow-1" style="min-width: 120px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Desde</label>
                    <input type="date" name="desde" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $desde; ?>">
                </div>
                <div class="flex-grow-1" style="min-width: 120px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Hasta</label>
                    <input type="date" name="hasta" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $hasta; ?>">
                </div>
                <div class="flex-grow-1" style="min-width: 140px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Operador</label>
                    <select name="id_usuario" class="form-select form-select-sm border-light-subtle fw-bold">
                        <option value="">Todos</option>
                        <?php 
                        $usuarios_lista = $conexion->query("SELECT id, usuario FROM usuarios ORDER BY usuario ASC")->fetchAll(PDO::FETCH_ASSOC);
                        foreach($usuarios_lista as $usu): ?>
                            <option value="<?php echo $usu['id']; ?>" <?php echo ($f_usu == $usu['id']) ? 'selected' : ''; ?>><?php echo strtoupper($usu['usuario']); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex-grow-0 d-flex gap-2 mt-2 mt-md-0">
                    <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3" style="height: 31px;">
                        <i class="bi bi-funnel-fill me-1"></i> FILTRAR
                    </button>
                    <a href="mermas.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;">
                        <i class="bi bi-trash3-fill me-1"></i> LIMPIAR
                    </a>
                </div>
            </form>
            </div>
        </div>
        <div class="row g-4">
            <div class="col-md-4">
                <div class="card card-custom border-0 shadow-sm">
                    <div class="card-header bg-white py-3 border-bottom-0"><h6 class="fw-bold mb-0">Informe de Pérdida</h6></div>
                    <div class="card-body bg-light rounded-bottom">
                        <form method="POST">
                            <div class="mb-3">
                                <label class="small fw-bold text-muted text-uppercase mb-1">Producto</label>
                                <select name="id_producto" id="selectProducto" class="form-select" required>
                                    <option></option>
                                    <?php foreach($productos as $p): ?>
                                        <option value="<?php echo $p->id; ?>"><?php echo htmlspecialchars($p->descripcion); ?> (Stock: <?php echo floatval($p->stock_actual); ?>)</option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="small fw-bold text-muted text-uppercase mb-1">Cantidad</label>
                                    <input type="number" step="0.01" name="cantidad" class="form-control fw-bold" required placeholder="0.00">
                                </div>
                                <div class="col-6">
                                    <label class="small fw-bold text-muted text-uppercase mb-1">Motivo</label>
                                    <select name="motivo" class="form-select">
                                        <option value="Vencido">📅 Vencido</option>
                                        <option value="Roto">🔨 Roto / Dañado</option>
                                        <option value="Robo">🦹 Robo / Falta</option>
                                        <option value="Consumo">☕ Consumo Interno</option>
                                        <option value="Otros">📦 Otros</option>
                                    </select>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="small fw-bold text-muted text-uppercase mb-1">Nota Adicional</label>
                                <textarea name="nota_adicional" class="form-control" rows="2" placeholder="Detalles del incidente..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary w-100 fw-bold shadow-sm">CONFIRMAR BAJA</button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card card-custom border-0 shadow-sm">
                    <div class="card-header bg-white py-3 border-bottom"><h6 class="fw-bold mb-0">Historial de Mermas</h6></div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0 tabla-movil-ajustada">
                            <thead class="bg-light small text-uppercase text-muted">
                                <tr>
                                    <th class="ps-4 py-3 text-start">Fecha</th>
                                    <th class="text-start">Producto</th>
                                    <th class="d-none d-md-table-cell">Motivo</th>
                                    <th class="text-end pe-4">Cant.</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($mermas) > 0): ?>
                                    <?php foreach($mermas as $m): 
                                        $jsonM = htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8');
                                    ?>
                                    <tr style="cursor:pointer" onclick="verTicketMerma(<?php echo $jsonM; ?>)">
                                        <td class="ps-4 text-start text-muted small">
                                            <div class="fw-bold text-dark"><?php echo date('d/m/Y', strtotime($m->fecha)); ?></div>
                                            <?php echo date('H:i', strtotime($m->fecha)); ?> hs
                                        </td>
                                        <td class="text-start">
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($m->descripcion); ?></div>
                                            <div class="d-block d-md-none mt-1 mb-1"><span class="badge bg-light text-dark border fw-normal" style="font-size: 0.7rem;"><?php echo $m->motivo; ?></span></div>
                                            <small class="text-muted"><i class="bi bi-person-circle"></i> <?php echo $m->usuario; ?></small>
                                        </td>
                                        <td class="d-none d-md-table-cell"><span class="badge bg-light text-dark border fw-normal"><?php echo $m->motivo; ?></span></td>
                                        <td class="text-end fw-bold text-danger pe-4">-<?php echo floatval($m->cantidad); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted">No hay registros en estas fechas.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script>
        $(document).ready(function() {
            $('#selectProducto').select2({ theme: 'bootstrap-5', placeholder: "Buscar...", allowClear: true });
            if(new URLSearchParams(window.location.search).get('msg') === 'ok') {
                Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Baja registrada', showConfirmButton: false, timer: 2500 });
            }
        });

        const miLocal = <?php echo json_encode($conf); ?>;
        const miFirma = "<?php echo file_exists($ruta_firma) ? $ruta_firma : ''; ?>";

        function verTicketMerma(merma) {
            let fechaF = new Date(merma.fecha).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            let perdidaF = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(merma.cantidad * merma.precio_costo);
            
            let linkPdfPublico = window.location.origin + "/ticket_merma_pdf.php?id=" + merma.id + "&v=" + Date.now();
            let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdfPublico);
            
            let logoHtml = miLocal.logo_url ? `<img src="${miLocal.logo_url}?v=${Date.now()}" style="max-height: 50px; margin-bottom: 10px;">` : '';
            let aclaracionOp = merma.nombre_completo ? (merma.nombre_completo + " | " + merma.nombre_rol).toUpperCase() : 'OPERADOR AUTORIZADO';
            let rutaFirmaOp = merma.id_op ? `img/firmas/usuario_${merma.id_op}.png?v=${Date.now()}` : `img/firmas/firma_admin.png?v=${Date.now()}`;
            let firmaHtml = `<img src="${rutaFirmaOp}" style="max-height: 80px; margin-bottom: -25px;" onerror="this.style.display='none'"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${aclaracionOp}</small>`;

            let ticketHTML = `
                <div id="printTicket" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
                    <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                        ${logoHtml}
                        <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${miLocal.nombre_negocio || 'MI NEGOCIO'}</h4>
                        <small style="color: #666;">CUIT: ${miLocal.cuit || 'S/N'}<br>${miLocal.direccion_local || ''}</small>
                    </div>
                    <div style="text-align: center; margin-bottom: 15px;">
                        <h5 style="font-weight: 900; color: #dc3545; letter-spacing: 1px; margin:0;">COMPROBANTE DE BAJA</h5>
                        <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">OP #${merma.id}</span>
                    </div>
                    <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                        <div style="margin-bottom: 4px;"><strong>FECHA:</strong> ${fechaF}</div>
                        <div style="margin-bottom: 4px;"><strong>PRODUCTO:</strong> ${merma.descripcion}</div>
                        <div style="margin-bottom: 4px;"><strong>CANTIDAD:</strong> ${parseFloat(merma.cantidad)} unidades</div>
                        <div><strong>OPERADOR:</strong> ${aclaracionOp}</div>
                    </div>
                    <div style="margin-bottom: 15px; font-size: 13px;">
                        <strong style="border-bottom: 1px solid #ccc; display:block; margin-bottom:5px;">MOTIVO:</strong>
                        ${merma.motivo}
                    </div>
                    <div style="background: #dc354510; border-left: 4px solid #dc3545; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                        <span style="font-size: 1.1em; font-weight:800;">TOTAL:</span>
                        <span style="font-size: 1.15em; font-weight:900; color: #dc3545;">-${perdidaF}</span>
                    </div>
                    <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
                        <div style="width: 45%; text-align: center;">${firmaHtml}</div>
                        <div style="width: 45%; text-align: center;">
                            <a href="${linkPdfPublico}" target="_blank"><img src="${qrUrl}" alt="QR" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;"></a>
                            <div style="font-size: 8px; color: #999; margin-top: 3px;">ESCANEAR PDF (PÚBLICO)</div>
                        </div>
                    </div>
                </div>
                
                <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3 no-print">
                    <button class="btn btn-sm btn-outline-dark fw-bold" onclick="window.open('${linkPdfPublico}', '_blank')"><i class="bi bi-file-earmark-pdf-fill"></i> PDF</button>
                    <button class="btn btn-sm btn-success fw-bold" onclick="mandarWAMerma('${merma.descripcion}', '${parseFloat(merma.cantidad)}', '${linkPdfPublico}')"><i class="bi bi-whatsapp"></i> WA</button>
                    <button class="btn btn-sm btn-primary fw-bold" onclick="mandarMailMerma(${merma.id})"><i class="bi bi-envelope"></i> EMAIL</button>
                </div>
            `;

            Swal.fire({ html: ticketHTML, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });
        }

        function mandarWAMerma(prod, cant, link) {
            let msj = `⚠️ Notificación de Baja de Stock:\nSe registraron *${cant} unidades* de baja para el producto *${prod}*.\n\n📄 Ver ticket oficial:\n${link}\n\nSaludos de *${miLocal.nombre_negocio}*.`;
            let tel = miLocal.telefono_whatsapp ? miLocal.telefono_whatsapp.replace(/[^0-9]/g, '') : '';
            let url = tel ? `https://wa.me/${tel}?text=${encodeURIComponent(msj)}` : `https://wa.me/?text=${encodeURIComponent(msj)}`;
            window.open(url, '_blank');
        }
        function mandarMailMerma(id) {
    Swal.fire({
        title: 'Notificar Baja', text: '¿A qué correo querés enviarlo?',
        input: 'email', inputPlaceholder: 'destino@correo.com',
        showCancelButton: true, confirmButtonText: 'Enviar'
    }).then((r) => {
        if(r.isConfirmed && r.value) {
            Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); }});
            let fData = new FormData(); fData.append('id', id); fData.append('email', r.value);
            fetch('acciones/enviar_email_merma.php', { method: 'POST', body: fData })
            .then(res => res.json()).then(d => {
                if(d.status === 'success') Swal.fire('¡Enviado!', 'El ticket de baja fue entregado.', 'success');
                else Swal.fire('Error', d.msg, 'error');
            });
        }
    });
}
    </script>
    <?php include 'includes/layout_footer.php'; ?>
</body>
</html>