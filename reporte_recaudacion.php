<?php
// reporte_recaudacion.php - VERSIÓN VANGUARD PRO (FLUJO NATIVO + GRÁFICOS)
session_start();
$es_publico = isset($_GET['publico']) && $_GET['publico'] == '1';
if (!isset($_SESSION['usuario_id']) && !$es_publico) { header("Location: index.php"); exit; }
require_once 'includes/db.php';

try {
    $conf = $conexion->query("SELECT * FROM configuracion LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $negocio = [
        'nombre' => $conf['nombre_negocio'] ?? 'EMPRESA',
        'direccion' => $conf['direccion_local'] ?? '',
        'logo' => $conf['logo_url'] ?? '',
        'cuit' => $conf['cuit'] ?? 'S/D'
    ];
    
    // 1. Datos del Operador
    $id_operador = $_SESSION['usuario_id'] ?? ($_GET['gen_by'] ?? 1);
    $u_op = $conexion->prepare("SELECT usuario FROM usuarios WHERE id = ?");
    $u_op->execute([$id_operador]);
    $operadorRow = $u_op->fetch(PDO::FETCH_ASSOC);
    $usuario_actual = strtoupper($operadorRow['usuario'] ?? 'S/D');

    // 2. Datos del Dueño para Firma
    $u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol 
                                 FROM usuarios u 
                                 JOIN roles r ON u.id_rol = r.id 
                                 WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
    $ownerRow = $u_owner->fetch(PDO::FETCH_ASSOC);
    $firmante = $ownerRow ? $ownerRow : ['nombre_completo' => 'RESPONSABLE', 'nombre_rol' => 'AUTORIZADO', 'id' => 0];

    // 3. Firma en Base64
    $firmaUsuario = ""; 
    if($ownerRow && file_exists("img/firmas/usuario_{$ownerRow['id']}.png")) {
        $firmaUsuario = 'data:image/png;base64,' . base64_encode(file_get_contents("img/firmas/usuario_{$ownerRow['id']}.png"));
    } elseif(file_exists("img/firmas/firma_admin.png")) {
        $firmaUsuario = 'data:image/png;base64,' . base64_encode(file_get_contents("img/firmas/firma_admin.png"));
    }

    $desde = $_GET['desde'] ?? date('Y-m-d');
    $hasta = $_GET['hasta'] ?? date('Y-m-d');
    $f_usu = $_GET['id_usuario'] ?? '';
    $buscar = trim($_GET['buscar'] ?? '');

    // --- MOTOR DE DATOS ---
    
    // A. VENTAS
    $condV = ["DATE(v.fecha) >= ?", "DATE(v.fecha) <= ?", "v.estado = 'completada'"];
    $paramsV = [$desde, $hasta];
    if($f_usu !== '') { $condV[] = "v.id_usuario = ?"; $paramsV[] = $f_usu; }
    if(!empty($buscar)) { $condV[] = "(v.codigo_ticket LIKE ? OR v.metodo_pago LIKE ?)"; array_push($paramsV, "%$buscar%", "%$buscar%"); }

    $stmtV = $conexion->prepare("SELECT v.*, u.usuario as cajero FROM ventas v LEFT JOIN usuarios u ON v.id_usuario = u.id WHERE " . implode(" AND ", $condV) . " ORDER BY v.fecha DESC");
    $stmtV->execute($paramsV);
    $ventas = $stmtV->fetchAll(PDO::FETCH_ASSOC);

    $ingresoVentas = 0; $metodos_pago = [];
    foreach($ventas as $v) {
        $ingresoVentas += (float)$v['total'];
        $m = !empty($v['metodo_pago']) ? strtoupper($v['metodo_pago']) : 'EFECTIVO';
        if(!isset($metodos_pago[$m])) $metodos_pago[$m] = 0;
        $metodos_pago[$m] += (float)$v['total'];
    }

    // B. SORTEOS
    $condS = ["DATE(st.fecha_compra) >= ?", "DATE(st.fecha_compra) <= ?", "st.pagado = 1"];
    $paramsS = [$desde, $hasta];
    $stmtS = $conexion->prepare("SELECT st.*, s.titulo, s.precio_ticket FROM sorteo_tickets st JOIN sorteos s ON st.id_sorteo = s.id WHERE " . implode(" AND ", $condS));
    $stmtS->execute($paramsS);
    $tickets_sorteo = $stmtS->fetchAll(PDO::FETCH_ASSOC);
    $ingresoSorteos = 0; foreach($tickets_sorteo as $t) { $ingresoSorteos += (float)$t['precio_ticket']; }
    if($ingresoSorteos > 0) { $metodos_pago['SORTEOS/RIFAS'] = $ingresoSorteos; }

    // C. GASTOS Y MERMAS
    $condG = ["DATE(fecha) >= ?", "DATE(fecha) <= ?"];
    $paramsG = [$desde, $hasta];
    if($f_usu !== '') { $condG[] = "id_usuario = ?"; $paramsG[] = $f_usu; }
    $stmtG = $conexion->prepare("SELECT * FROM gastos WHERE " . implode(" AND ", $condG));
    $stmtG->execute($paramsG);
    $gastos = $stmtG->fetchAll(PDO::FETCH_ASSOC);
    $totalGastos = 0; foreach($gastos as $g) { $totalGastos += (float)$g['monto']; }

    $stmtM = $conexion->prepare("SELECT m.cantidad, p.precio_costo FROM mermas m JOIN productos p ON m.id_producto = p.id WHERE DATE(m.fecha) BETWEEN ? AND ?");
    $stmtM->execute([$desde, $hasta]);
    $mermas = $stmtM->fetchAll(PDO::FETCH_ASSOC);
    $totalMermas = 0; foreach($mermas as $m) { $totalMermas += ((float)$m['cantidad'] * (float)$m['precio_costo']); }

    $recaudacionTotal = $ingresoVentas + $ingresoSorteos;
    $totalEgresos = $totalGastos + $totalMermas;
    $ingresoNeto = $recaudacionTotal - $totalEgresos;

    $rango_texto = ($desde == $hasta) ? date('d/m/Y', strtotime($desde)) : date('d/m/Y', strtotime($desde)) . " al " . date('d/m/Y', strtotime($hasta));
    arsort($metodos_pago);

    $url_actual = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    if (strpos($url_actual, 'publico=1') === false) {
        $separador = parse_url($url_actual, PHP_URL_QUERY) ? '&' : '?';
        $url_publica = $url_actual . $separador . 'publico=1&gen_by=' . $id_operador;
    } else { $url_publica = $url_actual; }
    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=0&data=" . urlencode($url_publica);

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Recaudación - Vanguard Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: 'Roboto', sans-serif; background: #f0f0f0; margin: 0; padding: 20px; color: #333; }
        .report-page { background: white; width: 100%; max-width: 210mm; margin: 0 auto; padding: 20px; box-sizing: border-box; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #102A57; padding-bottom: 10px; margin-bottom: 20px; }
        .empresa-info h1 { margin: 0; font-size: 16pt; color: #102A57; text-transform: uppercase; font-weight: 900; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; table-layout: fixed; }
        th { background: #102A57; color: white !important; padding: 10px; text-align: left; font-size: 9pt; white-space: nowrap; }
        td { border-bottom: 1px solid #eee; padding: 10px; font-size: 8.5pt; vertical-align: top; word-wrap: break-word; }
        thead { display: table-header-group; } .evitar-corte { page-break-inside: avoid; } 
        .resumen-card { background: #f8f9fa; border: 1px solid #ddd; padding: 15px; border-radius: 8px; }
        .footer-section { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; }
        .firma-area { width: 180px; text-align: center; } .firma-img { max-width: 150px; max-height: 80px; margin-bottom: -10px; }
        .firma-linea { border-top: 1px solid #000; padding-top: 5px; font-weight: bold; font-size: 8pt; text-transform: uppercase; }
        .no-print { position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: flex; gap: 10px; }
        .btn-descargar { background: #dc3545; color: white; padding: 15px 25px; border-radius: 50px; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
        .btn-compartir { background: #102A57; color: white; padding: 15px 25px; border-radius: 50px; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.3); }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="descargarPDF()" class="btn-descargar"><i class="bi bi-file-pdf"></i> DESCARGAR PDF</button>
        <button onclick="abrirModalCompartir()" class="btn-compartir"><i class="bi bi-share-fill"></i> COMPARTIR</button>
    </div>

    <div id="reporteContenido" class="report-page">
        <table>
            <thead>
                <tr>
                    <td colspan="4" style="border:none; padding:0; background:white;">
                        <header>
                            <div style="width: 20%; text-align: left;">
                                <?php if(!empty($negocio['logo'])): ?><img src="<?php echo $negocio['logo']; ?>" style="max-height: 60px;"><?php endif; ?>
                            </div>
                            <div class="empresa-info" style="width: 50%; text-align: center;">
                                <h1><?php echo strtoupper($negocio['nombre']); ?></h1>
                                <p style="font-size: 9pt; margin: 3px 0; font-weight: bold; color: #555;"><?php echo $negocio['direccion']; ?></p>
                                <p style="font-size: 9pt; margin: 0;"><strong>CUIT: <?php echo $negocio['cuit']; ?></strong></p>
                            </div>
                            <div style="text-align: right; width: 30%; font-size: 8pt; color: #333;">
                                <strong>REPORTE RECAUDACIÓN</strong><br><?php echo $rango_texto; ?><br>
                                <strong style="color:#102A57;">EMISIÓN: <?php echo date('d/m/Y H:i'); ?></strong>
                            </div>
                        </header>
                    </td>
                </tr>
            </thead>
        </table>

        <div class="evitar-corte">
            <div class="resumen-card" style="margin-bottom: 20px;">
                <h4 style="color: #102A57; border-bottom: 2px solid #102A57; padding-bottom: 5px; margin-top:0; text-transform: uppercase; font-size: 11pt;">CAJA NETA (LÍQUIDO DEL PERÍODO)</h4>
                <div style="text-align: center; margin: 15px 0;">
                    <span style="font-size: 32pt; font-weight: 900; color: #198754;">$<?php echo number_format($ingresoNeto, 2, ',', '.'); ?></span>
                </div>
                <div style="display: flex; gap: 20px;">
                    <div style="flex: 1; background: white; padding: 10px; border-radius: 5px; border: 1px solid #ddd; text-align: center;">
                        <small style="color: #102A57; font-weight: bold; text-transform: uppercase; font-size: 7pt;">Ingresos Brutos (+)</small>
                        <div style="font-size: 14pt; font-weight: 900; color: #102A57;">$<?php echo number_format($recaudacionTotal, 2, ',', '.'); ?></div>
                    </div>
                    <div style="flex: 1; background: #fdf2f2; padding: 10px; border-radius: 5px; border: 1px solid #dc3545; text-align: center;">
                        <small style="color: #dc3545; font-weight: bold; text-transform: uppercase; font-size: 7pt;">Total Egresos (-)</small>
                        <div style="font-size: 14pt; font-weight: 900; color: #dc3545;">-$<?php echo number_format($totalEgresos, 2, ',', '.'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="evitar-corte" style="display: flex; justify-content: space-between; gap: 15px; margin-bottom: 20px;">
            <div style="flex: 1; background: white; padding: 15px; border-radius: 5px; border: 1px solid #ddd;">
                <strong style="font-size: 8pt; color: #666; text-transform: uppercase; display:block; text-align:center; margin-bottom:10px;">Ingresos por Categoría ($)</strong>
                <div style="position: relative; height: 200px;"><canvas id="chartMetodos"></canvas></div>
            </div>
            <div style="flex: 1; background: white; padding: 15px; border-radius: 5px; border: 1px solid #ddd;">
                <strong style="font-size: 8pt; color: #666; text-transform: uppercase; display:block; text-align:center; margin-bottom:10px;">Balance Flujo (Entradas vs Salidas)</strong>
                <div style="position: relative; height: 200px;"><canvas id="chartBalance"></canvas></div>
            </div>
        </div>

        <table>
            <thead>
                <tr><th colspan="4" style="background: #198754; text-align: center; font-size: 10pt;">RESUMEN DE MOVIMIENTOS</th></tr>
                <tr><th style="width: 25%;">ORIGEN</th><th style="width: 25%; text-align: right;">MONTO</th><th style="width: 25%;">DESTINO</th><th style="width: 25%; text-align: right;">MONTO</th></tr>
            </thead>
            <tbody>
                <tr>
                    <td><strong>Ventas de Productos</strong></td>
                    <td style="text-align: right; color:#198754; font-weight:bold;">+$<?php echo number_format($ingresoVentas, 2, ',', '.'); ?></td>
                    <td><strong>Gastos Registrados</strong></td>
                    <td style="text-align: right; color:#dc3545; font-weight:bold;">-$<?php echo number_format($totalGastos, 2, ',', '.'); ?></td>
                </tr>
                <tr>
                    <td><strong>Ingresos por Sorteos</strong></td>
                    <td style="text-align: right; color:#198754; font-weight:bold;">+$<?php echo number_format($ingresoSorteos, 2, ',', '.'); ?></td>
                    <td><strong>Pérdida por Mermas</strong></td>
                    <td style="text-align: right; color:#dc3545; font-weight:bold;">-$<?php echo number_format($totalMermas, 2, ',', '.'); ?></td>
                </tr>
            </tbody>
        </table>

        <div class="footer-section evitar-corte">
            <div style="width: 40%; font-size: 8pt; color: #666; line-height: 1.4; text-align: justify;">
                <p><strong>DECLARACIÓN JURADA:</strong> Este reporte refleja fielmente el balance neto de la recaudación (Ingresos totales menos egresos) registradas en el sistema.</p>
            </div>
            <div style="width: 30%; text-align: center;">
                <img src="<?php echo $qr_url; ?>" style="width: 75px; height: 75px; border: 1px solid #ccc; padding: 2px;">
                <p style="font-size: 7pt; margin-top: 5px; color:#666; font-weight: bold;">Escanear para Validar</p>
            </div>
            <div class="firma-area" style="width: 30%;">
                <?php if(!empty($firmaUsuario)): ?><img src="<?php echo $firmaUsuario; ?>" class="firma-img"><?php else: ?><div style="height: 60px;"></div><?php endif; ?>
                <div class="firma-linea"><?php echo strtoupper($firmante['nombre_completo']); ?><br><span style="font-size: 7pt; color: #555; font-weight: normal;"><?php echo strtoupper($firmante['nombre_rol']); ?></span></div>
            </div>
        </div>
    </div>

    <script>
        const datosMetodos = <?php echo json_encode($metodos_pago); ?>;
        const totalIn = <?php echo $recaudacionTotal; ?>;
        const totalOut = <?php echo $totalEgresos; ?>;

        document.addEventListener("DOMContentLoaded", function() {
            if(Object.keys(datosMetodos).length > 0) {
                new Chart(document.getElementById('chartMetodos'), {
                    type: 'bar',
                    data: { labels: Object.keys(datosMetodos), datasets: [{ label: 'Monto ($)', data: Object.values(datosMetodos), backgroundColor: '#102A57', borderRadius: 4 }] },
                    options: { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { display: false } } }
                });
            }
            new Chart(document.getElementById('chartBalance'), {
                type: 'doughnut',
                data: { labels: ['Ingresos Brutos', 'Egresos (Salidas)'], datasets: [{ data: [totalIn, totalOut], backgroundColor: ['#198754', '#dc3545'] }] },
                options: { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { position: 'bottom', labels: { font: { size: 9 } } } } }
            });
        });

        const optGlobal = { margin: [15, 10, 25, 10], filename: 'Reporte_Recaudacion.pdf', image: { type: 'jpeg', quality: 0.98 }, html2canvas: { scale: 2 }, pagebreak: { mode: 'css', avoid: ['.evitar-corte'] }, jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' } };

        function descargarPDF() { html2pdf().set(optGlobal).from(document.getElementById('reporteContenido')).save(); }
        function abrirModalCompartir() {
            Swal.fire({
                title: 'Compartir Reporte', showCancelButton: true, confirmButtonText: '<i class="bi bi-whatsapp"></i> WhatsApp', confirmButtonColor: '#25D366', denyButtonText: '<i class="bi bi-envelope"></i> Correo', denyButtonColor: '#102A57', showDenyButton: true, cancelButtonText: 'Cerrar'
            }).then((result) => {
                if (result.isConfirmed) {
                    const msj = `💰 *REPORTE DE RECAUDACIÓN NETA*\n🏢 <?php echo $negocio['nombre']; ?>\n📅 Período: <?php echo $rango_texto; ?>\n💵 CAJA NETA: $<?php echo number_format($ingresoNeto, 2, ',', '.'); ?>\n📄 Ver online: ${window.location.href}`;
                    window.open(`https://wa.me/?text=${encodeURIComponent(msj)}`, '_blank');
                } else if (result.isDenied) {
                    Swal.fire({ title: 'Enviar por Correo', input: 'email', showCancelButton: true, confirmButtonText: 'ENVIAR', showLoaderOnConfirm: true, preConfirm: (email) => {
                        return html2pdf().set(optGlobal).from(document.getElementById('reporteContenido')).output('blob').then(blob => {
                            let fData = new FormData(); fData.append('email', email); fData.append('pdf_file', blob, 'Recaudacion.pdf');
                            return fetch('acciones/enviar_email_reporte_general.php', { method: 'POST', body: fData }).then(r => r.json());
                        });
                    }}).then((r) => { if(r.isConfirmed) Swal.fire('Enviado', 'Reporte enviado.', 'success'); });
                }
            });
        }
    </script>
</body>
</html>