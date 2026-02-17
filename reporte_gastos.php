<?php
// reporte_gastos.php - BOTÓN RESPONSIVO + DESCARGA DIRECTA
session_start();

if (!isset($_SESSION['usuario_id'])) { 
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    $actual_link = "$protocol://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
    header("Location: index.php?redirect=" . urlencode($actual_link));
    exit;
}

$db_encontrada = false;
$rutas = ['db.php', 'includes/db.php', '../db.php'];
foreach ($rutas as $ruta) { if (file_exists($ruta)) { require_once $ruta; $db_encontrada = true; break; } }
if (!$db_encontrada || !isset($conexion)) { die("Error de conexión."); }

try {
    $conf = $conexion->query("SELECT * FROM configuracion LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $negocio = [
        'nombre' => $conf['nombre_negocio'] ?? 'EMPRESA',
        'direccion' => $conf['direccion_local'] ?? $conf['direccion'] ?? '',
        'telefono' => $conf['telefono_whatsapp'] ?? $conf['telefono'] ?? '',
        'cuit' => $conf['cuit'] ?? 'S/D',
        'logo' => $conf['logo_url'] ?? ''
    ];

    $id_usuario = $_SESSION['usuario_id'];
    $u = $conexion->prepare("SELECT usuario, id_rol FROM usuarios WHERE id = ?");
    $u->execute([$id_usuario]);
    $userRow = $u->fetch(PDO::FETCH_ASSOC);
    
    $nombreUsuario = $userRow['usuario'] ?? 'Usuario';

    $firmaUsuario = ""; 
    if ($userRow['id_rol'] <= 2) { 
        if(file_exists("img/firmas/firma_admin.png")) $firmaUsuario = "img/firmas/firma_admin.png"; 
    } else { 
        if(file_exists("img/firmas/usuario_{$id_usuario}.png")) $firmaUsuario = "img/firmas/usuario_{$id_usuario}.png"; 
    }

    $gastos = $conexion->query("SELECT g.*, u.usuario FROM gastos g JOIN usuarios u ON g.id_usuario = u.id ORDER BY g.fecha DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    $total = 0;
    foreach($gastos as $g) { $total += $g['monto']; }

} catch (Exception $e) { die("Error: " . $e->getMessage()); }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reporte_Gastos_<?php echo date('Ymd'); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;700&display=swap" rel="stylesheet">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    
    <style>
        body { font-family: 'Roboto', sans-serif; font-size: 10pt; color: #333; margin: 0; padding: 0; background: #f0f0f0; }
        
        .page { 
            background: white; width: 210mm; min-height: 296mm; 
            padding: 15mm; margin: 0 auto; 
            position: relative; box-sizing: border-box; 
            overflow: hidden;
        }
        
        header { display: flex; justify-content: space-between; align-items: center; border-bottom: 2px solid #102A57; padding-bottom: 15px; margin-bottom: 20px; }
        .empresa-info h1 { margin: 0; font-size: 18pt; color: #102A57; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th { background: #102A57; color: white; padding: 8px; text-align: left; font-size: 9pt; }
        td { border-bottom: 1px solid #ddd; padding: 8px; font-size: 9pt; }
        .total-row td { border-top: 2px solid #102A57; font-weight: bold; font-size: 12pt; padding-top: 10px; }

        .footer-section { margin-top: 40px; display: flex; justify-content: space-between; align-items: flex-end; }
        
        /* FIRMA */
        .firma-area { width: 40%; text-align: center; position: relative; margin-top: 20px; }
        .firma-img { 
            max-width: 250px; 
            max-height: 110px; 
            display: block; 
            margin: 0 auto -28px auto;
            position: relative;
            z-index: 2;
        }
        .firma-linea { border-top: 1.5px solid #000; position: relative; z-index: 1; padding-top: 5px; font-weight: bold; font-size: 10pt; }

        /* --- BOTÓN DINÁMICO --- */
        .no-print { 
            position: fixed; 
            bottom: 20px; 
            right: 20px; 
            z-index: 9999; 
            display: flex;
            justify-content: flex-end;
        }
        
        .btn-descargar { 
            background: #dc3545; 
            color: white; 
            padding: 15px 30px; 
            border-radius: 50px; 
            text-decoration: none; 
            font-weight: bold; 
            border: none; 
            cursor: pointer; 
            font-size: 14px; 
            box-shadow: 0 4px 15px rgba(0,0,0,0.3); 
            transition: 0.3s;
        }

        /* AJUSTE PARA CELULAR */
        @media (max-width: 768px) {
            .no-print {
                left: 0;
                right: 0;
                bottom: 10px;
                padding: 0 15px;
                justify-content: center; /* Centrado */
            }
            .btn-descargar {
                width: 100%; /* Casi todo el ancho */
                padding: 20px; /* Más gordo para el dedo */
                font-size: 18px; /* Texto más grande */
                text-align: center;
                text-transform: uppercase;
            }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button onclick="descargarPDF()" class="btn-descargar">📥 DESCARGAR REPORTE</button>
    </div>

    <div id="reporteContenido" class="page">
        <header>
            <div style="width: 25%;">
                <?php if(!empty($negocio['logo'])): ?><img src="<?php echo $negocio['logo']; ?>" style="max-height: 75px;"><?php endif; ?>
            </div>
            <div class="empresa-info" style="text-align: center; width: 50%;">
                <h1><?php echo strtoupper($negocio['nombre']); ?></h1>
                <p><?php echo $negocio['direccion']; ?></p>
                <p><strong>CUIT: <?php echo $negocio['cuit']; ?></strong></p>
            </div>
            <div style="text-align: right; width: 25%; font-size: 9pt;">
                <strong>ID REPORTE:</strong><br><?php echo date('YmdHis'); ?><br>
                <strong>FECHA:</strong><br><?php echo date('d/m/Y'); ?>
            </div>
        </header>

        <h3 style="color: #102A57; border-left: 5px solid #102A57; padding-left: 10px; margin-bottom: 20px;">DETALLE DE GASTOS DE CAJA</h3>

        <table>
            <thead>
                <tr>
                    <th>FECHA</th>
                    <th>CONCEPTO</th>
                    <th>CATEGORÍA</th>
                    <th style="text-align: right;">MONTO</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($gastos as $g): ?>
                <tr>
                    <td><?php echo date('d/m/y H:i', strtotime($g['fecha'])); ?></td>
                    <td><?php echo strtoupper($g['descripcion']); ?> (<?php echo $g['usuario']; ?>)</td>
                    <td><span style="background: #f0f0f0; padding: 2px 5px; border-radius: 3px; font-size: 8pt;"><?php echo strtoupper($g['categoria']); ?></span></td>
                    <td style="text-align: right; font-weight: bold;">$<?php echo number_format($g['monto'], 2, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="total-row">
                    <td colspan="3" style="text-align: right;">TOTAL EGRESOS:</td>
                    <td style="text-align: right; color: #dc3545;">$<?php echo number_format($total, 2, ',', '.'); ?></td>
                </tr>
            </tbody>
        </table>

        <div class="footer-section">
            <div style="width: 55%; font-size: 8pt; color: #666; line-height: 1.4;">
                <p><strong>DECLARACIÓN JURADA:</strong> Este documento es fiel reflejo de las operaciones registradas en el sistema por el usuario responsable.</p>
                <p>Generado el <?php echo date('d/m/Y H:i'); ?></p>
            </div>
            
            <div class="firma-area">
                <?php if(!empty($firmaUsuario)): ?>
                    <img src="<?php echo $firmaUsuario; ?>" class="firma-img" alt="Firma">
                <?php else: ?>
                    <div style="height: 70px;"></div>
                <?php endif; ?>
                <div class="firma-linea">FIRMA CONFORME</div>
            </div>
        </div>
    </div>

    <script>
    function descargarPDF() {
        const element = document.getElementById('reporteContenido');
        const nombreArchivo = 'Reporte_Gastos_<?php echo date('d_m_Y_Hi'); ?>.pdf';
        const opt = {
            margin:       0,
            filename:     nombreArchivo,
            image:        { type: 'jpeg', quality: 0.98 },
            html2canvas:  { scale: 2, useCORS: true },
            jsPDF:        { unit: 'mm', format: 'a4', orientation: 'portrait' }
        };
        html2pdf().set(opt).from(element).save();
    }
    </script>
</body>
</html>