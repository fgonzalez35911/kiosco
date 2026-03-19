<?php
// reporte_pedido_proveedor.php - VERSIÓN VANGUARD PRO (FLUJO NATIVO)
session_start();
if (!isset($_SESSION['usuario_id']) || empty($_POST['datos'])) {
    die("Error crítico: No se recibieron datos para procesar el pedido.");
}
require_once 'includes/db.php';

try {
    $conf = $conexion->query("SELECT * FROM configuracion LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $negocio = [
        'nombre' => $conf['nombre_negocio'] ?? 'EMPRESA',
        'direccion' => $conf['direccion_local'] ?? '',
        'cuit' => $conf['cuit'] ?? 'S/D',
        'logo' => $conf['logo_url'] ?? ''
    ];

    $u_op = $conexion->prepare("SELECT usuario FROM usuarios WHERE id = ?");
    $u_op->execute([$_SESSION['usuario_id']]);
    $operadorRow = $u_op->fetch(PDO::FETCH_ASSOC);
    $usuario_actual = strtoupper($operadorRow['usuario'] ?? 'S/D');

    $empresa_proveedor = strtoupper($_POST['empresa'] ?? 'PROVEEDOR');
    $productos_pedir = json_decode($_POST['datos'], true);
    
    $numero_pedido = "PD-" . date('Ymd-His');

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Orden de Pedido - <?php echo $empresa_proveedor; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        body { font-family: 'Roboto', sans-serif; background: #f0f0f0; margin: 0; padding: 20px; color: #333; }
        .report-page { background: white; width: 100%; max-width: 210mm; margin: 0 auto; padding: 20px; box-sizing: border-box; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
        
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #102A57; padding-bottom: 10px; margin-bottom: 20px; }
        .empresa-info h1 { margin: 0; font-size: 16pt; color: #102A57; text-transform: uppercase; font-weight: 900; }
        
        table { width: 100%; border-collapse: collapse; margin-bottom: 15px; table-layout: fixed; }
        th { background: #102A57; color: white !important; padding: 10px; text-align: left; font-size: 9pt; white-space: nowrap; text-transform: uppercase; }
        td { border-bottom: 1px solid #eee; padding: 10px; font-size: 9.5pt; vertical-align: middle; word-wrap: break-word; }
        
        thead { display: table-header-group; } 
        .evitar-corte { page-break-inside: avoid; } 

        .no-print { position: fixed; bottom: 20px; right: 20px; z-index: 9999; display: flex; gap: 10px; }
        .btn-descargar { background: #dc3545; color: white; padding: 15px 25px; border-radius: 50px; border: none; cursor: pointer; font-weight: bold; box-shadow: 0 4px 10px rgba(0,0,0,0.3); font-size: 14px; }

        @media screen { .report-page { margin-bottom: 30px; } }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="descargarPDF()" class="btn-descargar"><i class="bi bi-file-pdf"></i> DESCARGAR Y GUARDAR ORDEN</button>
    </div>

    <div id="reporteContenido" class="report-page">
        <table>
            <thead>
                <tr>
                    <td colspan="2" style="border:none; padding:0; background:white;">
                        <header>
                            <div style="width: 20%; text-align: left;">
                                <?php if(!empty($negocio['logo'])): ?>
                                    <img src="<?php echo $negocio['logo']; ?>?v=<?php echo time(); ?>" style="max-height: 60px;">
                                <?php endif; ?>
                            </div>
                            <div class="empresa-info" style="width: 50%; text-align: center;">
                                <h1><?php echo strtoupper($negocio['nombre']); ?></h1>
                                <p style="font-size: 9pt; margin: 3px 0; font-weight: bold; color: #555;"><?php echo $negocio['direccion']; ?></p>
                            </div>
                            <div style="text-align: right; width: 30%; font-size: 8pt; color: #333; font-weight: normal;">
                                <strong>Nº ORDEN: <?php echo $numero_pedido; ?></strong><br>
                                <strong style="color:#102A57;">FECHA: <?php echo date('d/m/Y H:i'); ?></strong>
                            </div>
                        </header>
                        
                        <div style="background: #f8f9fa; padding: 15px; border-left: 5px solid #102A57; margin-bottom: 20px;">
                            <h3 style="margin: 0 0 5px 0; color: #102A57; text-transform: uppercase;">PROVEEDOR: <?php echo $empresa_proveedor; ?></h3>
                            <span style="font-size: 9pt; color: #666;">Por medio de la presente, solicitamos el abastecimiento de los siguientes artículos detallados a continuación.</span>
                        </div>
                    </td>
                </tr>
                <tr>
                    <th style="width: 75%;">PRODUCTO / DESCRIPCIÓN</th>
                    <th style="width: 25%; text-align: center;">CANTIDAD A PEDIR</th>
                </tr>
            </thead>
            
            <tbody>
                <?php foreach($productos_pedir as $p): ?>
                <tr class="evitar-corte">
                    <td><strong><?php echo strtoupper($p['desc']); ?></strong></td>
                    <td style="text-align: center; font-weight: 900; font-size: 11pt; color: #102A57; background: #f8f9fa; border: 1px solid #eee;"><?php echo $p['cant']; ?> UND</td>
                </tr>
                <?php endforeach; ?>
                
                <tr class="evitar-corte">
                    <td colspan="2" style="text-align: center; padding: 40px 20px; color: #a0a0a0; border-top: 2px dashed #e0e0e0;">
                        <i class="bi bi-cart-check-fill" style="font-size: 28pt; display: block; margin-bottom: 10px; color: #d0d0d0;"></i>
                        <span style="font-size: 10pt; font-weight: bold; text-transform: uppercase; letter-spacing: 2px;">Fin de la Orden de Pedido</span>
                    </td>
                </tr>
            </tbody>
        </table>

        <div class="evitar-corte" style="margin-top: 30px;">
            <div style="text-align: center; font-size: 8pt; color: #999; border-top: 1px solid #eee; padding-top: 15px;">
                Orden generada automáticamente por el módulo logístico de Vanguard Pro.<br>
                Solicitado por Operador: <strong><?php echo $usuario_actual; ?></strong>
            </div>
        </div>
    </div>

    <script>
    const optGlobal = { 
        margin: [15, 10, 20, 10], 
        filename: 'Pedido_<?php echo str_replace(' ', '_', $empresa_proveedor); ?>.pdf', 
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
            
            pdf.text('Orden para <?php echo addslashes($empresa_proveedor); ?> - ' + textoEmpresa, 10, 284);
            pdf.text('Página ' + i + ' de ' + totalPages, 200, 284, { align: 'right' });
        }
    }
    
    // Auto-descargar al abrir (Opcional, para comodidad del dueño)
    // setTimeout(descargarPDF, 1000); 
    </script>
</body>
</html>