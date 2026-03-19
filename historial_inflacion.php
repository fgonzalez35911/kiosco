<?php
// historial_inflacion.php - PANEL CON FILTRO DE RANGO DE FECHAS
session_start();
require_once 'includes/db.php';
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] > 2) { header("Location: dashboard.php"); exit; }

$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf['color_barra_nav'] ?? '#102A57';

// --- FILTROS UNIFICADOS ---
$desde = $_GET['desde'] ?? date('Y-m-d', strtotime('-2 months'));
$hasta = $_GET['hasta'] ?? date('Y-m-d');
$f_usu = $_GET['id_usuario'] ?? '';
$buscar = trim($_GET['buscar'] ?? '');

$cond = ["DATE(h.fecha) >= ?", "DATE(h.fecha) <= ?"];
$params = [$desde, $hasta];

if($f_usu !== '') { 
    $cond[] = "h.id_usuario = ?"; 
    $params[] = $f_usu; 
}
if(!empty($buscar)) { 
    $cond[] = "(h.grupo_afectado LIKE ? OR h.id = ?)"; 
    array_push($params, "%$buscar%", intval($buscar)); 
}

$sql = "SELECT h.*, u.usuario, u.nombre_completo, r.nombre as nombre_rol 
        FROM historial_inflacion h 
        LEFT JOIN usuarios u ON h.id_usuario = u.id 
        LEFT JOIN roles r ON u.id_rol = r.id
        WHERE " . implode(" AND ", $cond) . " 
        ORDER BY h.fecha DESC";

$stmt = $conexion->prepare($sql);
$stmt->execute($params);
$historial = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Cálculos para Widgets
$total_registros = count($historial);
$total_productos_afectados = ($total_registros > 0) ? array_sum(array_column($historial, 'cantidad_productos')) : 0;
$query_filtros = !empty($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : "desde=$desde&hasta=$hasta";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Historial de Inflación</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        @media (max-width: 768px) {
            .tabla-movil-ajustada td, .tabla-movil-ajustada th { padding: 0.4rem 0.2rem !important; font-size: 0.75rem !important; white-space: nowrap; }
            .tabla-movil-ajustada .badge { font-size: 0.65rem !important; }
            .tabla-movil-ajustada .small { font-size: 0.65rem !important; }
            .tabla-movil-ajustada .fw-bold { font-size: 0.75rem !important; }
        }
    </style>
</head>
<body class="bg-light">
    <?php include 'includes/layout_header.php'; ?>
    
    <?php
// --- DEFINICIÓN DEL BANNER DINÁMICO ---
$titulo = "Historial de Inflación";
$subtitulo = "Registro de aumentos masivos aplicados.";
$icono_bg = "bi-clock-history";

$botones = [
    ['texto' => 'Reporte PDF', 'link' => "reporte_inflacion.php?$query_filtros", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-3 px-md-4 py-2 shadow-sm', 'target' => '_blank']
];

$widgets = [
    ['label' => 'Actualizaciones', 'valor' => $total_registros, 'icono' => 'bi-arrow-repeat', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Productos Afectados', 'valor' => $total_productos_afectados, 'icono' => 'bi-box-seam', 'border' => 'border-info', 'icon_bg' => 'bg-info bg-opacity-20'],
    ['label' => 'Rango Días', 'valor' => (int)((strtotime($hasta) - strtotime($desde)) / 86400) . ' d.', 'icono' => 'bi-calendar3', 'border' => 'border-warning', 'icon_bg' => 'bg-warning bg-opacity-20']
];

include 'includes/componente_banner.php'; 
?>

    <div class="container-fluid container-md mt-n4 pb-5 px-2 px-md-3" style="position: relative; z-index: 20;">
        
        <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border-left: 5px solid #ff9800 !important;">
            <div class="card-body p-2 p-md-3">
                <form method="GET" class="row g-2 align-items-center mb-0">
                    <input type="hidden" name="desde" value="<?php echo htmlspecialchars($desde); ?>">
                    <input type="hidden" name="hasta" value="<?php echo htmlspecialchars($hasta); ?>">
                    <input type="hidden" name="id_usuario" value="<?php echo htmlspecialchars($f_usu); ?>">
                    <div class="col-md-8 col-12 text-center text-md-start">
                        <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador Rápido</h6>
                        <p class="small mb-0 opacity-75 d-none d-md-block">Busca un registro por nombre del Proveedor.</p>
                    </div>
                    <div class="col-md-4 col-12 text-end mt-2 mt-md-0">
                        <div class="input-group input-group-sm">
                            <input type="text" name="buscar" class="form-control border-0 fw-bold shadow-none" placeholder="Buscar Proveedor..." value="<?php echo htmlspecialchars($buscar); ?>">
                            <button class="btn btn-dark px-3 shadow-none border-0" type="submit"><i class="bi bi-arrow-right-circle-fill"></i></button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-4 mb-4">
            <div class="card-body p-2 p-md-3">
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
                        <a href="historial_inflacion.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3" style="height: 31px; display: flex; align-items: center;">
                            <i class="bi bi-trash3-fill me-1"></i> LIMPIAR
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="alert py-2 small mb-3 text-center fw-bold border-0 shadow-sm rounded-3" style="background-color: #e9f2ff; color: #102A57;">
            <i class="bi bi-hand-index-thumb-fill me-1"></i> Toca o haz clic en un registro para ver el comprobante
        </div>

        <div class="card border-0 shadow-sm rounded-4 overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 tabla-movil-ajustada">
                    <thead class="bg-light text-muted small text-uppercase">
                        <tr>
                            <th class="ps-4">Fecha / Hora</th>
                            <th>Grupo Afectado</th>
                            <th class="d-none d-md-table-cell">Productos</th>
                            <th class="d-none d-md-table-cell">Impacto</th>
                            <th class="text-end pe-4">Aumento</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if(empty($historial)): ?>
                            <tr><td colspan="5" class="text-center py-5 text-muted">No hay aumentos registrados en este rango de fechas.</td></tr>
                        <?php else: ?>
                            <?php foreach($historial as $h): 
                            $jsonData = htmlspecialchars(json_encode($h), ENT_QUOTES, 'UTF-8');
                            $es_costo = (strtoupper($h['accion']) == 'COSTO');
                            $badge_impacto = '<span class="badge ' . ($es_costo ? 'bg-danger' : 'bg-primary') . '">' . ($es_costo ? 'COSTO Y VENTA' : 'SOLO VENTA') . '</span>';
                        ?>
                        <tr style="cursor:pointer;" onclick="verInflacion(<?php echo $jsonData; ?>)">
                                <td class="ps-4 py-3">
                                    <div class="fw-bold text-dark"><?php echo date('d/m/Y', strtotime($h['fecha'])); ?></div>
                                    <small class="text-muted"><?php echo date('H:i', strtotime($h['fecha'])); ?> hs</small>
                                </td>
                                <td>
                                    <div class="fw-bold text-dark"><?php echo strtoupper(htmlspecialchars($h['grupo_afectado'])); ?></div>
                                    <div class="d-block d-md-none mt-1 mb-1">
                                        <?php echo $badge_impacto; ?> 
                                        <span class="badge bg-light text-dark border ms-1"><?php echo $h['cantidad_productos']; ?> ítems</span>
                                    </div>
                                    <small class="text-muted"><i class="bi bi-person-circle"></i> <?php echo htmlspecialchars($h['usuario'] ?? 'Sistema'); ?></small>
                                </td>
                                <td class="d-none d-md-table-cell"><span class="badge bg-light text-dark border"><?php echo $h['cantidad_productos']; ?> ítems</span></td>
                                <td class="d-none d-md-table-cell"><?php echo $badge_impacto; ?></td>
                                <td class="text-end pe-4 fw-bold text-danger fs-5">+<?php echo number_format($h['porcentaje'], 2); ?>%</td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
const miLocal = <?php echo json_encode($conf); ?>;

function verInflacion(inf) {
    let porcentajeF = parseFloat(inf.porcentaje).toFixed(2) + '%';
    let fechaF = new Date(inf.fecha).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    let linkPdfPublico = window.location.origin + "/ticket_inflacion_pdf.php?id=" + inf.id;
    let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdfPublico);
    let logoHtml = miLocal.logo_url ? `<img src="${miLocal.logo_url}" style="max-height: 50px; margin-bottom: 10px;">` : '';
    
    // Firma del Operador: Grande (80px) y sobre la línea
    let aclaracionOp = inf.nombre_completo ? (inf.nombre_completo + " | " + inf.nombre_rol).toUpperCase() : (inf.usuario || 'OPERADOR').toUpperCase();
    let rutaFirmaOp = inf.id_usuario ? `img/firmas/usuario_${inf.id_usuario}.png?v=${Date.now()}` : `img/firmas/firma_admin.png?v=${Date.now()}`;
    let firmaHtml = `<img src="${rutaFirmaOp}" style="max-height: 80px; margin-bottom: -25px;" onerror="this.style.display='none'"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${aclaracionOp}</small>`;

    let ticketHTML = `
        <div id="printTicket" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
            <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                ${logoHtml}
                <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${miLocal.nombre_negocio}</h4>
                <small style="color: #666;">${miLocal.direccion_local}</small>
            </div>
            <div style="text-align: center; margin-bottom: 15px;">
                <h5 style="font-weight: 900; color: #dc3545; letter-spacing: 1px; margin:0;">COMPROBANTE INFLACIÓN</h5>
                <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">OP #${inf.id}</span>
            </div>
            <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                <div style="margin-bottom: 4px;"><strong>FECHA:</strong> ${fechaF}</div>
                <div style="margin-bottom: 4px;"><strong>GRUPO:</strong> ${inf.grupo_afectado.toUpperCase()}</div>
                <div style="margin-bottom: 4px;"><strong>AFECTA A:</strong> ${inf.accion == 'COSTO' ? 'COSTO Y VENTA' : 'SOLO VENTA'}</div>
                <div><strong>OPERADOR:</strong> ${aclaracionOp}</div>
            </div>
            <div style="background: #dc354510; border-left: 4px solid #dc3545; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <span style="font-size: 1.1em; font-weight:800;">AUMENTO:</span>
                <span style="font-size: 1.15em; font-weight:900; color: #dc3545;">+${porcentajeF}</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
            <div style="width: 45%; text-align: center;">
                ${firmaHtml}
            </div>

            <div style="width: 45%; text-align: center;">
                <a href="${linkPdfPublico}" target="_blank" style="text-decoration: none;">
                    <img src="${qrUrl}" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px; display: block; margin: 0 auto;">
                    <div style="font-size: 8px; color: #999; margin-top: 5px; font-weight: bold;">ESCANEAR PDF (PÚBLICO)</div>
                </a>
            </div>
</div>
        </div>
        <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3 no-print">
            <button class="btn btn-sm btn-outline-dark fw-bold" onclick="window.open('${linkPdfPublico}', '_blank')">PDF</button>
            <button class="btn btn-sm btn-success fw-bold" onclick="mandarWAInflacion('${inf.grupo_afectado}', '${porcentajeF}', '${linkPdfPublico}')">WA</button>
            <button class="btn btn-sm btn-primary fw-bold" onclick="mandarMailInflacion(${inf.id})">EMAIL</button>
        </div>
    `;
    Swal.fire({ html: ticketHTML, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });
}

function mandarWAInflacion(grupo, porcentaje, link) {
    let msj = `Se registró aumento de *${porcentaje}* por inflación en el grupo *${grupo}*.\n📄 Comprobante: ${link}`;
    window.open(`https://wa.me/?text=${encodeURIComponent(msj)}`, '_blank');
}

function mandarMailInflacion(id) {
    Swal.fire({ 
        title: 'Enviar Comprobante', 
        text: 'Ingrese el correo electrónico del destinatario:',
        input: 'email', 
        showCancelButton: true,
        confirmButtonText: 'ENVIAR AHORA',
        cancelButtonText: 'CANCELAR'
    }).then((r) => {
        if(r.isConfirmed && r.value) {
            Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            let fData = new FormData(); 
            fData.append('id', id); 
            fData.append('email', r.value);
            fetch('acciones/enviar_email_inflacion.php', { method: 'POST', body: fData })
            .then(res => res.json())
            .then(d => { 
                Swal.fire(d.status === 'success' ? 'Enviado con éxito' : 'Error al enviar', d.msg || '', d.status); 
            })
            .catch(error => {
                Swal.fire('Error', 'Hubo un problema de conexión con el servidor.', 'error');
            });
        }
    });
}
</script>
<?php include 'includes/layout_footer.php'; ?>
</body>
</html>