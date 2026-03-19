<?php
// reporte_bienes.php - VERSIÓN VANGUARD PRO (FLUJO NATIVO + GRÁFICOS)
session_start();
$es_publico = isset($_GET['publico']) && $_GET['publico'] == '1';
if (!isset($_SESSION['usuario_id']) && !$es_publico) { header("Location: index.php"); exit; }
require_once 'includes/db.php';

try {
    $conf = $conexion->query("SELECT * FROM configuracion LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $negocio = [
        'nombre' => $conf['nombre_negocio'] ?? 'EMPRESA',
        'direccion' => $conf['direccion_local'] ?? '',
        'cuit' => $conf['cuit'] ?? 'S/D',
        'logo' => $conf['logo_url'] ?? ''
    ];

    // 1. Datos del Operador
    $id_operador_gen = $_SESSION['usuario_id'] ?? ($_GET['gen_by'] ?? 1);
    $u_op = $conexion->prepare("SELECT usuario FROM usuarios WHERE id = ?");
    $u_op->execute([$id_operador_gen]);
    $operadorRow = $u_op->fetch(PDO::FETCH_ASSOC);
    $usuario_actual = strtoupper($operadorRow['usuario'] ?? 'S/D');

    // 2. Datos del Dueño para la Firma
    $u_owner = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol 
                                 FROM usuarios u JOIN roles r ON u.id_rol = r.id 
                                 WHERE r.nombre = 'dueño' OR r.nombre = 'DUEÑO' LIMIT 1");
    $ownerRow = $u_owner->fetch(PDO::FETCH_ASSOC);
    $firmante = $ownerRow ? $ownerRow : ['nombre_completo' => 'RESPONSABLE', 'nombre_rol' => 'AUTORIZADO', 'id' => 0];

    // 3. Firma física en Base64
    $firmaUsuario = ""; 
    if($ownerRow && file_exists("img/firmas/usuario_{$ownerRow['id']}.png")) {
        $firmaUsuario = 'data:image/png;base64,' . base64_encode(file_get_contents("img/firmas/usuario_{$ownerRow['id']}.png"));
    } elseif(file_exists("img/firmas/firma_admin.png")) {
        $firmaUsuario = 'data:image/png;base64,' . base64_encode(file_get_contents("img/firmas/firma_admin.png"));
    }

    $desde = $_GET['desde'] ?? date('Y-01-01', strtotime('-5 years'));
    $hasta = $_GET['hasta'] ?? date('Y-12-31');
    $buscar = trim($_GET['buscar'] ?? '');

    $cond = ["((DATE(fecha_compra) >= ? AND DATE(fecha_compra) <= ?) OR fecha_compra IS NULL)"];
    $params = [$desde, $hasta];

    if(!empty($buscar)) {
        $cond[] = "(nombre LIKE ? OR marca LIKE ? OR numero_serie LIKE ? OR ubicacion LIKE ?)";
        array_push($params, "%$buscar%", "%$buscar%", "%$buscar%", "%$buscar%");
    }

    // Consulta de Flujo Continuo
    $stmt = $conexion->prepare("SELECT * FROM bienes_uso WHERE " . implode(" AND ", $cond) . " ORDER BY id DESC");
    $stmt->execute($params);
    $registros = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rango_texto = date('d/m/Y', strtotime($desde)) . " al " . date('d/m/Y', strtotime($hasta));

    // --- CÁLCULOS ESTADÍSTICOS PARA RESUMEN Y GRÁFICOS ---
    $total_bienes = count($registros);
    $totalValor = 0;
    
    $estados_bienes = [];
    $top_costos = [];

    foreach($registros as $r) {
        $costo = floatval($r['costo_compra']);
        $totalValor += $costo;

        // Agrupar para Gráfico Circular (Estado)
        $est = ucfirst(strtolower(trim((string)$r['estado'])));
        if(!$est) { $est = 'Desconocido'; }
        if(!isset($estados_bienes[$est])) { $estados_bienes[$est] = 0; }
        $estados_bienes[$est]++;

        // Agrupar para Gráfico de Barras (Top Inversión)
        $nombre_bien = strtoupper($r['nombre']);
        if(!isset($top_costos[$nombre_bien])) { $top_costos[$nombre_bien] = 0; }
        $top_costos[$nombre_bien] += $costo;
    }
    arsort($top_costos);

    $url_actual = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    if (strpos($url_actual, 'publico=1') === false) {
        $separador = parse_url($url_actual, PHP_URL_QUERY) ? '&' : '?';
        $url_publica = $url_actual . $separador . 'publico=1&gen_by=' . $id_operador_gen;
    } else {
        $url_publica = $url_actual;
    }
    $qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=0&data=" . urlencode($url_publica);

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte Activos - Vanguard Pro</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <style>
        body { font-family: 'Roboto', sans-serif; background: #f0f0f0; margin: 0; padding: 20px; color: #333; }
        .report-page { background: white; width: 100%; max-width: 210mm; margin: 0 auto; padding: 20px; box-sizing: border-box; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #102A57; padding-bottom: 10px; margin-bottom: 20px; }
        .empresa-info h1 { margin: 0; font-size: 16pt; color: #102A57; text-transform: uppercase; font-weight: 900; }
        
        /* ESTRUCTURA NATIVA PARA EVITAR CORTES DE TABLA */
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; table-layout: fixed; }
        th { background: #102A57; color: white !important; padding: 10px; text-align: left; font-size: 9pt; white-space: nowrap; text-transform: uppercase; }
        td { border-bottom: 1px solid #eee; padding: 10px; font-size: 8.5pt; vertical-align: top; word-wrap: break-word; }
        
        thead { display: table-header-group; } 
        .evitar-corte { page-break-inside: avoid; } 

        .resumen-card { background: #f8f9fa; border: 1px solid #ddd; padding: 15px; border-radius: 8px; }
        .footer-section { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; }
        .firma-area { width: 180px; text-align: center; }
        .firma-img { max-width: 150px; max-height: 80px; margin-bottom: -10px; }
        .firma-linea { border-top: 1px solid #000; padding-top: 5px; font-weight: bold; font-size: 8pt; text-transform: uppercase; }
        
        .no-print { position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: flex; gap: 10px; }
        .btn-descargar { background: #dc3545; color: white; padding: 15px 25px; border-radius: 50px; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.3); font-size: 14px; }
        .btn-compartir { background: #102A57; color: white; padding: 15px 25px; border-radius: 50px; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.3); font-size: 14px; }

        @media screen { .report-page { margin-bottom: 30px; } }
        
        /* Badges de estado */
        .badge { padding: 4px 8px; border-radius: 4px; font-size: 7.5pt; font-weight: bold; text-transform: uppercase; color: white; display: inline-block; }
        .bg-nuevo { background-color: #198754; }
        .bg-bueno { background-color: #0dcaf0; color: #000 !important; }
        .bg-regular { background-color: #ffc107; color: #000 !important; }
        .bg-reparar { background-color: #dc3545; }
        .bg-malo { background-color: #212529; }
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
                    <td colspan="6" style="border:none; padding:0; background:white;">
                        <header>
                            <div style="width: 20%; text-align: left;">
                                <?php if(!empty($negocio['logo'])): ?>
                                    <img src="<?php echo $negocio['logo']; ?>?v=<?php echo time(); ?>" style="max-height: 60px;">
                                <?php endif; ?>
                            </div>
                            <div class="empresa-info" style="width: 50%; text-align: center;">
                                <h1><?php echo strtoupper($negocio['nombre']); ?></h1>
                                <p style="font-size: 9pt; margin: 3px 0; font-weight: bold; color: #555;"><?php echo $negocio['direccion']; ?></p>
                                <p style="font-size: 9pt; margin: 0;"><strong>CUIT: <?php echo $negocio['cuit']; ?></strong></p>
                            </div>
                            <div style="text-align: right; width: 30%; font-size: 8pt; color: #333; font-weight: normal;">
                                <strong>INVENTARIO DE ACTIVOS</strong><br>
                                <strong style="color:#102A57;">EMISIÓN: <?php echo date('d/m/Y H:i'); ?></strong>
                            </div>
                        </header>
                        <h3 style="color: #102A57; border-left: 5px solid #102A57; padding-left: 10px; margin-bottom: 20px; margin-top:0; text-transform: uppercase; font-size: 11pt;">
                            Detalle de Bienes de Uso Físicos
                        </h3>
                    </td>
                </tr>
                <tr>
                    <th style="width: 15%;">FECHA COMPRA</th>
                    <th style="width: 25%;">EQUIPO</th>
                    <th style="width: 20%;">MARCA / MODELO</th>
                    <th style="width: 10%; text-align: center;">ESTADO</th>
                    <th style="width: 15%;">UBICACIÓN</th>
                    <th style="width: 15%; text-align: right;">VALOR INVERSIÓN</th>
                </tr>
            </thead>
            
            <tbody>
                <?php if($total_bienes > 0): ?>
                    <?php foreach($registros as $r): 
                        $estadoClass = 'bg-malo';
                        $estLower = strtolower($r['estado'] ?? '');
                        if ($estLower == 'nuevo') $estadoClass = 'bg-nuevo';
                        if ($estLower == 'bueno') $estadoClass = 'bg-bueno';
                        if ($estLower == 'mantenimiento') $estadoClass = 'bg-regular';
                        if ($estLower == 'roto') $estadoClass = 'bg-reparar';
                        if ($estLower == 'baja') $estadoClass = 'bg-malo';
                    ?>
                    <tr class="evitar-corte">
                        <td><?php echo $r['fecha_compra'] ? date('d/m/Y', strtotime($r['fecha_compra'])) : '<i style="color:#999;">Sin fecha</i>'; ?></td>
                        <td>
                            <strong><?php echo strtoupper($r['nombre']); ?></strong><br>
                            <small style="color:#666;">S/N: <?php echo $r['numero_serie'] ?: '-'; ?></small>
                        </td>
                        <td><?php echo strtoupper($r['marca'] . ' ' . $r['modelo']); ?></td>
                        <td style="text-align: center;"><span class="badge <?php echo $estadoClass; ?>"><?php echo $r['estado']; ?></span></td>
                        <td><?php echo strtoupper($r['ubicacion']); ?></td>
                        <td style="text-align: right; font-weight: bold; color: #102A57;">$<?php echo number_format($r['costo_compra'], 2, ',', '.'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <tr class="evitar-corte">
                        <td colspan="6" style="text-align: center; padding: 40px 20px; color: #a0a0a0; border-top: 2px dashed #e0e0e0;">
                            <i class="bi bi-pc-display-horizontal" style="font-size: 28pt; display: block; margin-bottom: 10px; color: #d0d0d0;"></i>
                            <span style="font-size: 10pt; font-weight: bold; text-transform: uppercase; letter-spacing: 2px;">Fin de Registros de Activos</span>
                        </td>
                    </tr>
                <?php else: ?>
                    <tr><td colspan="6" style="text-align:center; padding: 30px; color:#666;">No se encontraron activos registrados en este filtro.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php if($total_bienes > 0): ?>
        <div class="evitar-corte" style="margin-top: 30px;">
            <div class="resumen-card">
                <h4 style="color: #102A57; border-bottom: 2px solid #102A57; padding-bottom: 5px; margin-bottom: 15px; margin-top:0; text-transform: uppercase; font-size: 11pt;">Resumen de Inversión</h4>
                
                <div style="display: flex; gap: 20px; margin-bottom: 20px;">
                    <div style="flex: 1; background: white; padding: 10px; border-radius: 5px; border: 1px solid #ddd; text-align: center;">
                        <small style="color: #666; font-weight: bold; text-transform: uppercase; font-size: 7pt;">Total de Equipos</small>
                        <div style="font-size: 16pt; font-weight: 900; color: #102A57;"><?php echo $total_bienes; ?></div>
                    </div>
                    <div style="flex: 1; background: #e8fdf2; padding: 10px; border-radius: 5px; border: 1px solid #198754; text-align: center;">
                        <small style="color: #198754; font-weight: bold; text-transform: uppercase; font-size: 7pt;">Valorización Total Inventario</small>
                        <div style="font-size: 16pt; font-weight: 900; color: #198754;">$<?php echo number_format($totalValor, 2, ',', '.'); ?></div>
                    </div>
                </div>

                <h4 style="color: #102A57; border-bottom: 2px solid #102A57; padding-bottom: 5px; margin-bottom: 15px; margin-top: 15px; text-transform: uppercase; font-size: 11pt;">Análisis Gráfico</h4>
                
                <div style="display: flex; justify-content: space-between; gap: 15px; width: 100%; box-sizing: border-box;">
                    <div style="flex: 1; background: white; padding: 15px; border-radius: 5px; border: 1px solid #ddd; box-sizing: border-box;">
                        <strong style="font-size: 8pt; color: #666; text-transform: uppercase; display:block; text-align:center; margin-bottom:10px;">Top Activos Costosos ($)</strong>
                        <div style="position: relative; height: 220px; width: 100%;"><canvas id="chartTopCostos"></canvas></div>
                    </div>
                    <div style="flex: 1; background: white; padding: 15px; border-radius: 5px; border: 1px solid #ddd; box-sizing: border-box;">
                        <strong style="font-size: 8pt; color: #666; text-transform: uppercase; display:block; text-align:center; margin-bottom:10px;">Estado del Hardware</strong>
                        <div style="position: relative; height: 220px; width: 100%;"><canvas id="chartEstado"></canvas></div>
                    </div>
                </div>
            </div>
            
            <script> 
                const datosCostos = <?php echo json_encode($top_costos); ?>; 
                const datosEstado = <?php echo json_encode($estados_bienes); ?>; 
            </script>

            <div class="footer-section">
                <div style="width: 40%; font-size: 8pt; color: #666; line-height: 1.4; text-align: justify;">
                    <p><strong>DECLARACIÓN JURADA:</strong> Este reporte refleja fielmente el inventario de bienes de uso físicos de la empresa y su valuación histórica de compra. Su validez puede ser verificada escaneando el código QR.</p>
                </div>
                <div style="width: 30%; text-align: center;">
                    <img src="<?php echo $qr_url; ?>" style="width: 75px; height: 75px; border: 1px solid #ccc; padding: 2px;">
                    <p style="font-size: 7pt; margin-top: 5px; color:#666; font-weight: bold;">Escanear para Validar</p>
                </div>
                <div class="firma-area" style="width: 30%;">
                    <?php if(!empty($firmaUsuario)): ?>
                        <img src="<?php echo $firmaUsuario; ?>" class="firma-img">
                    <?php else: ?>
                        <div style="height: 60px;"></div>
                    <?php endif; ?>
                    <div class="firma-linea">
                        <?php echo strtoupper($firmante['nombre_completo']); ?><br>
                        <span style="font-size: 7pt; color: #555; font-weight: normal;"><?php echo strtoupper($firmante['nombre_rol']); ?></span>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>
    document.addEventListener("DOMContentLoaded", function() {
        // Gráfico de Barras: Top Costos
        if(typeof datosCostos !== 'undefined' && Object.keys(datosCostos).length > 0) {
            const allLabelsCostos = Object.keys(datosCostos);
            const labelsCostos = allLabelsCostos.slice(0, 6).map(l => l.length > 13 ? l.substring(0, 13) + '...' : l);
            const dataCostos = allLabelsCostos.slice(0, 6).map(l => datosCostos[l]);

            if(document.getElementById('chartTopCostos')) {
                new Chart(document.getElementById('chartTopCostos'), {
                    type: 'bar',
                    data: { 
                        labels: labelsCostos, 
                        datasets: [{ 
                            label: 'Valor ($)', 
                            data: dataCostos, 
                            backgroundColor: '#102A57', 
                            borderRadius: 4,
                            maxBarThickness: 35 
                        }] 
                    },
                    options: { 
                        layout: { padding: { bottom: 15 } },
                        responsive: true, maintainAspectRatio: false, animation: false, 
                        plugins: { legend: { display: false } }, 
                        scales: { 
                            x: { ticks: { font: { size: 8 }, maxRotation: 45, minRotation: 45 } },
                            y: { beginAtZero: true, ticks: { font: { size: 8 } } } 
                        } 
                    }
                });
            }
        }

        // Gráfico Circular: Estado
        if(typeof datosEstado !== 'undefined') {
            const labelsEstado = Object.keys(datosEstado);
            const dataEstado = labelsEstado.map(l => datosEstado[l]);
            
            const coloresEstado = {
                'Nuevo': '#198754',
                'Bueno': '#0dcaf0',
                'Mantenimiento': '#ffc107',
                'Roto': '#dc3545',
                'Baja': '#212529',
                'Desconocido': '#6c757d'
            };

            const bgColors = labelsEstado.map(l => coloresEstado[l] || '#6c757d');

            const totalEstado = dataEstado.reduce((a, b) => a + b, 0);

            if(document.getElementById('chartEstado') && totalEstado > 0) {
                new Chart(document.getElementById('chartEstado'), {
                    type: 'doughnut',
                    data: { 
                        labels: labelsEstado, 
                        datasets: [{ data: dataEstado, backgroundColor: bgColors, borderWidth: 1 }] 
                    },
                    options: { 
                        layout: { padding: { bottom: 10 } },
                        responsive: true, maintainAspectRatio: false, animation: false, 
                        plugins: { legend: { position: 'bottom', labels: { boxWidth: 8, font: { size: 8 }, padding: 8 } } },
                        cutout: '55%'
                    }
                });
            }
        }
    });

    // MÁRGENES DE TITANIO VANGUARD PRO
    const optGlobal = { 
        margin: [15, 10, 25, 10], 
        filename: 'Reporte_Activos_Corporativo.pdf', 
        image: { type: 'jpeg', quality: 0.98 }, 
        html2canvas: { scale: 2, useCORS: true, scrollY: 0 }, 
        pagebreak: { mode: 'css', avoid: ['.evitar-corte'] }, 
        jsPDF: { unit: 'mm', format: 'a4', orientation: 'portrait' } 
    };

    function descargarPDF() {
        const element = document.getElementById('reporteContenido');
        html2pdf().set(optGlobal).from(element).toPdf().get('pdf').then(function (pdf) { 
            aplicarFormatoPaginas(pdf); 
        }).save();
    }

    function aplicarFormatoPaginas(pdf) {
        const totalPages = pdf.internal.getNumberOfPages();
        for (let i = 1; i <= totalPages; i++) {
            pdf.setPage(i);
            pdf.setDrawColor(200, 200, 200); 
            pdf.setLineWidth(0.5); 
            pdf.line(10, 280, 200, 280);
            pdf.setFontSize(7.5); 
            pdf.setTextColor(100, 100, 100);
            
            const textoEmpresa = '<?php echo addslashes(strtoupper($negocio['nombre'])); ?>';
            const textoGenerador = '<?php echo addslashes($usuario_actual); ?>';
            
            pdf.text('Inventario de Activos - ' + textoEmpresa, 10, 284);
            pdf.text('Página ' + i + ' de ' + totalPages, 200, 284, { align: 'right' });
            pdf.text('Emisión: <?php echo date('d/m/Y H:i'); ?> | Solicitado por: ' + textoGenerador, 10, 288);

            if (i < totalPages) {
                pdf.setFont('helvetica', 'italic'); 
                pdf.setTextColor(150, 150, 150);
                pdf.text('Continúa en la página siguiente...', 105, 278, { align: 'center' });
            }
        }
    }

    function abrirModalCompartir() {
        Swal.fire({
            title: 'Compartir Reporte',
            text: '¿Cómo desea enviar este documento oficial?',
            icon: 'info',
            showCancelButton: true,
            confirmButtonText: '<i class="bi bi-whatsapp"></i> WhatsApp',
            confirmButtonColor: '#25D366',
            denyButtonText: '<i class="bi bi-envelope"></i> Correo',
            denyButtonColor: '#102A57',
            showDenyButton: true,
            cancelButtonText: 'Cerrar'
        }).then((result) => {
            if (result.isConfirmed) {
                const msj = `🖥️ *INVENTARIO DE ACTIVOS DE LA EMPRESA*\n🏢 <?php echo $negocio['nombre']; ?>\n📄 Ver online: ${window.location.href}`;
                window.open(`https://wa.me/?text=${encodeURIComponent(msj)}`, '_blank');
            } else if (result.isDenied) {
                enviarPorEmailReal();
            }
        });
    }

    function enviarPorEmailReal() {
        Swal.fire({
            title: 'Enviar por Correo', input: 'email', inputPlaceholder: 'Ingrese el correo del destinatario...',
            showCancelButton: true, confirmButtonText: 'ENVIAR AHORA', confirmButtonColor: '#102A57', showLoaderOnConfirm: true,
            preConfirm: (email) => {
                const element = document.getElementById('reporteContenido');
                return html2pdf().set(optGlobal).from(element).toPdf().get('pdf').then(function(pdf) {
                    aplicarFormatoPaginas(pdf); return pdf.output('blob');
                }).then(blob => {
                    let fData = new FormData();
                    fData.append('email', email); fData.append('pdf_file', blob, 'Reporte_Bienes_Uso.pdf');
                    return fetch('acciones/enviar_email_reporte_general.php', { method: 'POST', body: fData })
                    .then(r => { if(!r.ok) throw new Error(r.statusText); return r.json(); })
                    .catch(e => Swal.showValidationMessage(`Error: ${e}`));
                });
            },
            allowOutsideClick: () => !Swal.isLoading()
        }).then((r) => { if(r && r.isConfirmed && r.value && r.value.status === 'success') Swal.fire({ icon: 'success', title: '¡Enviado!', text: 'El reporte oficial ha sido enviado.' }); });
    }
    </script>
</body>
</html>