<?php
// etiquetas_pdf.php - GENERADOR DE ETIQUETAS DE GÓNDOLA
require_once 'includes/db.php';

// Si recibimos un ID específico, imprimimos solo ese. Si no, imprimimos todos los que cambiaron precio hoy.
$filtro = "";
$params = [];
if(isset($_GET['id'])) {
    $filtro = "AND id = ?";
    $params[] = $_GET['id'];
}

$sql = "SELECT * FROM productos WHERE activo = 1 $filtro ORDER BY descripcion ASC";
$stmt = $conexion->prepare($sql);
$stmt->execute($params);
$productos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Etiquetas Góndola</title>
    <style>
        body { font-family: sans-serif; margin: 0; padding: 0; }
        .hoja { width: 210mm; min-height: 297mm; padding: 10mm; box-sizing: border-box; display: flex; flex-wrap: wrap; content-visibility: auto; }
        
        /* ETIQUETA INDIVIDUAL (Tamaño estándar aprox 6cm x 4cm) */
        .etiqueta {
            width: 60mm; height: 38mm;
            border: 1px dashed #999;
            margin: 2mm;
            padding: 2mm;
            display: flex; flex-direction: column; justify-content: space-between;
            page-break-inside: avoid;
        }
        
        .prod-nombre { font-size: 12px; font-weight: bold; height: 15px; overflow: hidden; text-transform: uppercase; }
        .prod-precio { font-size: 28px; font-weight: 900; text-align: right; margin: 0; }
        .prod-precio span { font-size: 14px; font-weight: normal; vertical-align: top; }
        .prod-meta { display: flex; justify-content: space-between; align-items: end; }
        .barcode-box { text-align: left; }
        .fecha { font-size: 8px; color: #555; }
        
        @media print {
            body { margin: 0; }
            .no-print { display: none; }
            .etiqueta { border: 1px solid #ccc; } /* Borde suave para guiar corte */
        }
    </style>
</head>
<body>
    <div class="no-print" style="padding: 20px; background: #eee; text-align: center;">
        <button onclick="window.print()" style="font-size: 20px; padding: 10px 20px;">🖨️ IMPRIMIR ETIQUETAS</button>
    </div>

    <div class="hoja">
        <?php foreach($productos as $p): ?>
        <div class="etiqueta">
            <div class="prod-nombre"><?php echo $p['descripcion']; ?></div>
            <div class="prod-precio"><span>$</span><?php echo number_format($p['precio_venta'], 0, ',', '.'); ?></div>
            
            <div class="prod-meta">
                <div class="barcode-box">
                    <img src="https://bwipjs-api.metafloor.com/?bcid=code128&text=<?php echo $p['codigo_barras'] ?? $p['id']; ?>&scale=2&height=5&incltext=N" style="height: 15px; width: auto;">
                    <div style="font-size: 9px;"><?php echo $p['codigo_barras']; ?></div>
                </div>
                <div class="fecha"><?php echo date('d/m/Y'); ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</body>
</html>