<?php
// cuenta_proveedor.php - TICKET PREMIUM + DEUDA VINCULADA + COMPROBANTE AUTO
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (file_exists('db.php')) require_once 'db.php';
elseif (file_exists('includes/db.php')) require_once 'includes/db.php';
else die("Error: No se encuentra db.php");

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] > 2) { header("Location: dashboard.php"); exit; }

$id_proveedor = intval($_GET['id'] ?? 0);
if ($id_proveedor <= 0) { header("Location: proveedores.php"); exit; }

$conf = $conexion->query("SELECT nombre_negocio, direccion_local, cuit, logo_url, color_barra_nav FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf['color_barra_nav'] ?? '#102A57';

$id_usuario = $_SESSION['usuario_id'];
$ruta_firma = "img/firmas/firma_admin.png";
if (!file_exists($ruta_firma) && file_exists("img/firmas/usuario_{$id_usuario}.png")) {
    $ruta_firma = "img/firmas/usuario_{$id_usuario}.png";
}

$stmt = $conexion->prepare("SELECT * FROM proveedores WHERE id = ?");
$stmt->execute([$id_proveedor]);
$prov = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$prov) die("Proveedor no encontrado.");

$facturas_query = $conexion->prepare("SELECT id, comprobante, monto, fecha FROM movimientos_proveedores WHERE id_proveedor = ? AND tipo = 'compra' ORDER BY fecha DESC");
$facturas_query->execute([$id_proveedor]);
$lista_facturas = $facturas_query->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['monto'])) {
    $tipo = $_POST['tipo'];
    $monto = (float)$_POST['monto'];
    $desc = trim($_POST['descripcion']);
    $comp = trim($_POST['comprobante']);
    $id_fact_asoc = !empty($_POST['id_factura_asociada']) ? $_POST['id_factura_asociada'] : null;
    $fecha = $_POST['fecha'] . ' ' . date('H:i:s');
    
    if ($monto > 0) {
        $sql = "INSERT INTO movimientos_proveedores (id_proveedor, tipo, monto, descripcion, comprobante, id_factura_asociada, fecha, id_usuario) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $conexion->prepare($sql)->execute([$id_proveedor, $tipo, $monto, $desc, $comp, $id_fact_asoc, $fecha, $_SESSION['usuario_id']]);
        header("Location: cuenta_proveedor.php?id=$id_proveedor&msg=ok"); exit;
    }
}

$historial = $conexion->prepare("SELECT m.*, u.usuario as nombre_operador, u.nombre_completo, f.comprobante as comp_asociado FROM movimientos_proveedores m LEFT JOIN usuarios u ON m.id_usuario = u.id LEFT JOIN movimientos_proveedores f ON m.id_factura_asociada = f.id WHERE m.id_proveedor = ? ORDER BY m.fecha DESC");
$historial->execute([$id_proveedor]);
$movimientos = $historial->fetchAll(PDO::FETCH_ASSOC);

$sqlSaldo = "SELECT (SELECT COALESCE(SUM(monto),0) FROM movimientos_proveedores WHERE id_proveedor = ? AND tipo = 'compra') - 
                    (SELECT COALESCE(SUM(monto),0) FROM movimientos_proveedores WHERE id_proveedor = ? AND tipo = 'pago') as saldo";
$stmtSaldo = $conexion->prepare($sqlSaldo);
$stmtSaldo->execute([$id_proveedor, $id_proveedor]);
$saldo_total = $stmtSaldo->fetchColumn();

$max_id = $conexion->query("SELECT MAX(id) FROM movimientos_proveedores")->fetchColumn();
$siguiente_comp = str_pad(($max_id ? $max_id + 1 : 1), 6, '0', STR_PAD_LEFT);

include 'includes/layout_header.php'; 
?>

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden; z-index: 10;">
    <i class="bi bi-person-badge bg-icon-large" style="z-index: 0;"></i>
    <div class="container position-relative" style="z-index: 2;">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-center gap-3 mb-4">
            <div>
                <a href="proveedores.php" class="text-white-50 text-decoration-none small fw-bold mb-2 d-inline-block"><i class="bi bi-arrow-left"></i> VOLVER A LISTA</a>
                <h2 class="font-cancha mb-0 text-white"><?php echo htmlspecialchars($prov['empresa']); ?></h2>
                <p class="opacity-75 mb-0 text-white small">Cuenta Corriente de Proveedor</p>
            </div>
            <div class="header-widget" style="min-width: 280px; background: rgba(0,0,0,0.2) !important; border: 1px solid rgba(255,255,255,0.1);">
                <div>
                    <div class="widget-label text-white-50">Saldo Pendiente</div>
                    <div class="widget-value text-white">$ <?php echo number_format($saldo_total, 2, ',', '.'); ?></div>
                </div>
                <div class="icon-box bg-white bg-opacity-10 text-white">
                    <i class="bi <?php echo ($saldo_total > 0) ? 'bi-exclamation-circle-fill' : 'bi-check-circle-fill'; ?>"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5 mt-4">
    <div class="row g-4">
        <div class="col-lg-4">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 border-bottom-0">
                    <h6 class="fw-bold mb-0"><i class="bi bi-pencil-square text-primary me-2"></i>Registrar Movimiento</h6>
                </div>
                <div class="card-body bg-light rounded-bottom">
                    <form method="POST">
                        <div class="mb-3 text-center">
                            <label class="small fw-bold text-muted text-uppercase d-block mb-2">¿Qué vas a registrar?</label>
                            <div class="btn-group w-100">
                                <input type="radio" class="btn-check" name="tipo" id="t_compra" value="compra" checked onchange="toggleTipo()">
                                <label class="btn btn-outline-danger fw-bold" for="t_compra">Factura (Deuda)</label>
                                <input type="radio" class="btn-check" name="tipo" id="t_pago" value="pago" onchange="toggleTipo()">
                                <label class="btn btn-outline-success fw-bold" for="t_pago">Pago (-)</label>
                            </div>
                        </div>

                        <div class="mb-3" id="div_vincular_factura" style="display:none;">
                            <label class="small fw-bold text-muted">Aplica a Factura / Deuda (Opcional)</label>
                            <select name="id_factura_asociada" class="form-select border-success">
                                <option value="">-- Pago a cuenta general --</option>
                                <?php foreach($lista_facturas as $fac): ?>
                                    <option value="<?php echo $fac['id']; ?>">OP #<?php echo $fac['id']; ?> <?php echo $fac['comprobante'] ? '- Comp: '.$fac['comprobante'] : ''; ?> ($<?php echo number_format($fac['monto'],0,',','.'); ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-floating mb-3">
                            <input type="number" step="0.01" name="monto" class="form-control fw-bold fs-4" id="floatingMonto" placeholder="0.00" required>
                            <label for="floatingMonto">Monto ($)</label>
                        </div>
                        <div class="row g-2 mb-3">
                            <div class="col-6"><div class="form-floating"><input type="date" name="fecha" class="form-control" id="floatingFecha" value="<?php echo date('Y-m-d'); ?>" required><label for="floatingFecha">Fecha</label></div></div>
                            <div class="col-6"><div class="form-floating"><input type="text" name="comprobante" class="form-control bg-light fw-bold text-primary" id="floatingComp" placeholder="N°" value="<?php echo $siguiente_comp; ?>" readonly><label for="floatingComp">N° Comp. (Auto)</label></div></div>
                        </div>
                        <div class="form-floating mb-4">
                            <textarea name="descripcion" class="form-control" placeholder="Detalle" id="floatingDesc" style="height: 100px"></textarea>
                            <label for="floatingDesc">Notas / Concepto</label>
                        </div>
                        <button type="submit" class="btn btn-danger w-100 py-3 fw-bold shadow-sm rounded-pill" id="btnAccion">
                            REGISTRAR FACTURA (DEUDA) <i class="bi bi-arrow-right"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-8">
            <div class="card card-custom border-0 shadow-sm h-100">
                <div class="card-header bg-white py-3 border-bottom d-flex justify-content-between">
                    <h6 class="fw-bold mb-0">Historial de Movimientos</h6>
                    <span class="badge bg-light text-dark border"><?php echo count($movimientos); ?> registros</span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 text-center">
                        <thead class="bg-light small text-muted text-uppercase">
                            <tr><th class="ps-4 text-start">Fecha</th><th class="text-start">Concepto</th><th class="text-end">Monto</th><th class="pe-4 text-end">Haber (-)</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach($movimientos as $m): 
                                $es_compra = ($m['tipo'] == 'compra');
                                $jsonM = htmlspecialchars(json_encode($m), ENT_QUOTES, 'UTF-8');
                            ?>
                            <tr style="cursor:pointer" onclick="abrirTicketPremium(<?php echo $jsonM; ?>)">
                                <td class="ps-4 text-start">
                                    <div class="fw-bold"><?php echo date('d/m/Y', strtotime($m['fecha'])); ?></div>
                                    <small class="text-muted"><?php echo date('H:i', strtotime($m['fecha'])); ?> hs</small>
                                </td>
                                <td class="text-start">
                                    <div class="d-flex align-items-center">
                                        <div class="rounded-circle p-2 me-2 <?php echo $es_compra ? 'bg-danger bg-opacity-10 text-danger' : 'bg-success bg-opacity-10 text-success'; ?>">
                                            <i class="bi <?php echo $es_compra ? 'bi-file-earmark-arrow-up' : 'bi-cash-coin'; ?>"></i>
                                        </div>
                                        <div>
                                            <div class="fw-bold small"><?php echo $es_compra ? 'Factura (Deuda)' : 'Pago Realizado'; ?></div>
                                            <small class="text-muted"><?php echo $m['comprobante'] ? '['.$m['comprobante'].'] ' : ''; ?><?php echo htmlspecialchars(substr($m['descripcion'], 0, 30)); ?>...</small>
                                            <?php if($m['id_factura_asociada']): ?>
                                                <div class="small text-success fw-bold" style="font-size: 10px;">→ PAGA OP #<?php echo $m['id_factura_asociada']; ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-end fw-bold text-danger"><?php echo $es_compra ? '$ '.number_format($m['monto'], 2, ',', '.') : '-'; ?></td>
                                <td class="text-end fw-bold text-success pe-4"><?php echo !$es_compra ? '$ '.number_format($m['monto'], 2, ',', '.') : '-'; ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    const miLocal = <?php echo json_encode($conf); ?>;
    const miFirma = "<?php echo file_exists($ruta_firma) ? $ruta_firma : ''; ?>";
    const prov_empresa = "<?php echo htmlspecialchars($prov['empresa']); ?>";
    const prov_telefono = "<?php echo htmlspecialchars($prov['telefono'] ?? ''); ?>";
    const prov_email = "<?php echo htmlspecialchars($prov['email'] ?? ''); ?>";

    function abrirTicketPremium(m) {
        let fechaF = new Date(m.fecha).toLocaleString('es-AR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
        let montoF = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' }).format(m.monto);
        let color = (m.tipo === 'compra') ? '#dc3545' : '#198754';
        let titulo = (m.tipo === 'compra') ? 'FACTURA (DEUDA)' : 'RECIBO DE PAGO';
        
        let linkPdfPublico = window.location.origin + "/ticket_proveedor_pdf.php?id=" + m.id + "&v=" + Date.now();
        let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdfPublico);
        
        let logoHtml = miLocal.logo_url ? `<img src="${miLocal.logo_url}?v=${Date.now()}" style="max-height: 50px; margin-bottom: 10px;">` : '';
        let nombreFirma = m.nombre_completo ? m.nombre_completo.toUpperCase() : 'FIRMA AUTORIZADA';
        
        // AQUI SE DESTRUYE EL CACHÉ DE LA FIRMA EN EL MODAL
        let firmaHtml = miFirma ? `<img src="${miFirma}?v=${Date.now()}" style="max-height: 50px;"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${nombreFirma}</small>` : '';

        let txtAsociado = m.id_factura_asociada ? `<div style="margin-bottom: 4px; color:#198754;"><strong>PAGA FACTURA:</strong> OP #${m.id_factura_asociada} ${m.comp_asociado ? '('+m.comp_asociado+')' : ''}</div>` : '';

        let ticketHTML = `
            <div id="printTicket" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
                <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                    ${logoHtml}
                    <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${miLocal.nombre_negocio || 'MI NEGOCIO'}</h4>
                    <small style="color: #666;">CUIT: ${miLocal.cuit || 'S/N'}<br>${miLocal.direccion_local || ''}</small>
                </div>
                <div style="text-align: center; margin-bottom: 15px;">
                    <h5 style="font-weight: 900; color: ${color}; letter-spacing: 1px; margin:0;">${titulo}</h5>
                    <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">OP #${m.id}</span>
                </div>
                <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                    <div style="margin-bottom: 4px;"><strong>FECHA:</strong> ${fechaF}</div>
                    <div style="margin-bottom: 4px;"><strong>PROVEEDOR:</strong> ${prov_empresa.toUpperCase()}</div>
                    <div style="margin-bottom: 4px;"><strong>COMPROBANTE:</strong> ${m.comprobante || 'N/A'}</div>
                    ${txtAsociado}
                    <div><strong>OPERADOR:</strong> ${(m.nombre_operador || 'ADMIN').toUpperCase()}</div>
                </div>
                <div style="margin-bottom: 15px; font-size: 13px;">
                    <strong style="border-bottom: 1px solid #ccc; display:block; margin-bottom:5px;">CONCEPTO / DETALLE:</strong>
                    ${m.descripcion || 'Sin descripción.'}
                </div>
                <div style="background: ${color}10; border-left: 4px solid ${color}; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                    <span style="font-size: 1.1em; font-weight:800;">TOTAL:</span>
                    <span style="font-size: 1.5em; font-weight:900; color: ${color};">${montoF}</span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
                    <div style="width: 45%; text-align: center;">${firmaHtml}</div>
                    <div style="width: 45%; text-align: center;">
                        <a href="${linkPdfPublico}" target="_blank">
                            <img src="${qrUrl}" alt="QR" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;">
                        </a>
                        <div style="font-size: 8px; color: #999; margin-top: 3px;">ESCANEAR PDF (PÚBLICO)</div>
                    </div>
                </div>
            </div>
            
            <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3 no-print">
                <button class="btn btn-sm btn-outline-dark fw-bold" onclick="descargarPDF(${m.id})"><i class="bi bi-file-earmark-pdf-fill"></i> PDF</button>
                <button class="btn btn-sm btn-success fw-bold" onclick="mandarWA('${titulo}', '${montoF}', '${linkPdfPublico}')"><i class="bi bi-whatsapp"></i> WA</button>
                <button class="btn btn-sm btn-primary fw-bold" onclick="mandarMail(${m.id}, '${prov_email}')"><i class="bi bi-envelope"></i> EMAIL</button>
            </div>
        `;

        Swal.fire({ html: ticketHTML, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });
    }

    function descargarPDF(id) { window.open('ticket_proveedor_pdf.php?id=' + id + '&v=' + Date.now(), '_blank'); }

    function mandarWA(titulo, monto, linkPdf) {
        let msj = `Hola *${prov_empresa}*!\nTe envío comprobante de *${titulo}* por el importe de *${monto}*.\n\n📄 *Podés ver y descargar el ticket digital aquí:*\n${linkPdf}\n\nSaludos de *${miLocal.nombre_negocio}*.`;
        let cel = prov_telefono.replace(/[^0-9]/g, '');
        let link = cel ? `https://wa.me/${cel}?text=${encodeURIComponent(msj)}` : `https://wa.me/?text=${encodeURIComponent(msj)}`;
        window.open(link, '_blank');
    }

    function mandarMail(id_mov, email_actual) {
        let asunto = `Comprobante de Operación - ${miLocal.nombre_negocio}`;
        let cuerpo = `Hola ${prov_empresa},\n\nTe enviamos el enlace para descargar tu comprobante de operación.\n\n👉 Ver PDF aquí: ${window.location.origin}/ticket_proveedor_pdf.php?id=${id_mov}\n\nSaludos cordiales,\n${miLocal.nombre_negocio}`;
        
        if(email_actual) { 
            window.location.href = `mailto:${email_actual}?subject=${encodeURIComponent(asunto)}&body=${encodeURIComponent(cuerpo)}`;
        } else {
            Swal.fire({
                title: 'Falta el Email',
                text: 'El proveedor no tiene correo. ¿A dónde lo enviamos?',
                input: 'email', inputPlaceholder: 'correo@ejemplo.com',
                showCancelButton: true, confirmButtonText: 'Abrir Mail', cancelButtonText: 'Cancelar'
            }).then((r) => { if(r.isConfirmed && r.value) window.location.href = `mailto:${r.value}?subject=${encodeURIComponent(asunto)}&body=${encodeURIComponent(cuerpo)}`; });
        }
    }

    function toggleTipo() {
        const esCompra = document.getElementById('t_compra').checked;
        const btn = document.getElementById('btnAccion');
        const divFac = document.getElementById('div_vincular_factura');
        if(esCompra) {
            btn.innerHTML = 'REGISTRAR FACTURA (DEUDA) <i class="bi bi-arrow-right"></i>';
            btn.className = 'btn btn-danger w-100 py-3 fw-bold shadow-sm rounded-pill';
            divFac.style.display = 'none';
        } else {
            btn.innerHTML = 'REGISTRAR PAGO <i class="bi bi-check-lg"></i>';
            btn.className = 'btn btn-success w-100 py-3 fw-bold shadow-sm rounded-pill';
            divFac.style.display = 'block';
        }
    }
    window.addEventListener('load', toggleTipo);

    if(new URLSearchParams(window.location.search).get('msg') === 'ok') {
        Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Registrado correctamente', showConfirmButton: false, timer: 2500 });
    }
</script>
<?php include 'includes/layout_footer.php'; ?>