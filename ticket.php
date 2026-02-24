<?php
// ticket.php - VERSIÓN PROFESIONAL CON CALCULADORA DE AHORRO Y FIDELIZACIÓN CORREGIDA
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { die("Acceso denegado"); }

$id_venta = $_GET['id'] ?? 0;

// 1. DATOS VENTA (Actualizado para traer puntos del cliente)
$stmt = $conexion->prepare("SELECT v.*, u.usuario, c.nombre as cliente, c.dni_cuit as dni, c.puntos_acumulados
                            FROM ventas v 
                            JOIN usuarios u ON v.id_usuario = u.id 
                            JOIN clientes c ON v.id_cliente = c.id 
                            WHERE v.id = ?");
$stmt->execute([$id_venta]);
$venta = $stmt->fetch(PDO::FETCH_ASSOC);

if(!$venta) die("Venta no encontrada");

// 2. DETALLES PRODUCTOS
$stmtDet = $conexion->prepare("SELECT d.*, p.descripcion, p.precio_venta as precio_regular
                              FROM detalle_ventas d 
                              JOIN productos p ON d.id_producto = p.id 
                              WHERE d.id_venta = ?");
$stmtDet->execute([$id_venta]);
$detalles = $stmtDet->fetchAll(PDO::FETCH_ASSOC);

// 3. DATOS DEUDA Y PAGOS MIXTOS (Lógica original preservada)
$stmtDeuda = $conexion->prepare("SELECT monto FROM movimientos_cc WHERE id_venta = ? AND tipo = 'haber' LIMIT 1");
$stmtDeuda->execute([$id_venta]);
$pago_deuda_info = $stmtDeuda->fetch(PDO::FETCH_ASSOC);
$monto_deuda_pagado = $pago_deuda_info ? $pago_deuda_info['monto'] : 0;

$pagos_mixtos = [];
if($venta['metodo_pago'] === 'Mixto') {
    $stmtMix = $conexion->prepare("SELECT * FROM pagos_ventas WHERE id_venta = ?");
    $stmtMix->execute([$id_venta]);
    $pagos_mixtos = $stmtMix->fetchAll(PDO::FETCH_ASSOC);
}

// 4. CONFIGURACIÓN DINÁMICA
$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);

// --- CÁLCULO DE AHORRO DE OFERTAS ---
// --- CÁLCULO DE AHORROS REALES ---
$subtotal_real_productos = 0;
$ahorro_ofertas = 0;

foreach($detalles as $d) {
    $subtotal_real_productos += $d['subtotal'];
    
    // CAMBIO: Usamos precio_historico que es la columna real de tu base de datos
    if($d['precio_regular'] > $d['precio_historico']) {
        $diferencia = $d['precio_regular'] - $d['precio_historico'];
        $ahorro_ofertas += $diferencia * $d['cantidad'];
    }
}


$ahorro_total = ($venta['descuento_monto_cupon'] ?? 0) + ($venta['descuento_manual'] ?? 0);
$saldo_favor_usado = $subtotal_real_productos - $ahorro_total - $venta['total'];
if($saldo_favor_usado < 0.05) $saldo_favor_usado = 0;

$ahorro_final_cliente = $ahorro_total + $saldo_favor_usado + $ahorro_ofertas;
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ticket #<?php echo $id_venta; ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Courier New', Courier, monospace; }
        body { width: 100%; background: #fff; color: #000; font-size: 12px; }
        .ticket { width: 100%; max-width: 290px; margin: 0 auto; padding: 8px; }
        .centrado { text-align: center; }
        .derecha { text-align: right; }
        .negrita { font-weight: bold; }
        .linea { border-top: 1px dashed #000; margin: 5px 0; }
        .logo-ticket { max-width: 100px; height: auto; margin-bottom: 5px; filter: grayscale(100%); }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: 2px 0; }
        .cantidad { width: 30px; }
        .precio { text-align: right; width: 75px; }
        .totales { margin-top: 5px; font-size: 13px; }
        .ahorro-box { border: 1px solid #000; padding: 4px; margin-top: 5px; font-size: 11px; text-align: center; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body onload="window.print()">
    <div class="ticket">
        <div class="centrado">
            <?php if(!empty($conf['logo_url'])): ?>
                <img src="<?php echo htmlspecialchars($conf['logo_url']); ?>" alt="Logo" class="logo-ticket">
            <?php endif; ?>

            <h3 class="negrita" style="font-size: 16px;"><?php echo htmlspecialchars($conf['nombre_negocio']); ?></h3>
            
            <?php if(!empty($conf['cuit'])): ?>
                <p>CUIT: <?php echo htmlspecialchars($conf['cuit']); ?></p>
            <?php endif; ?>
            
            <p><?php echo htmlspecialchars($conf['direccion_local']); ?></p>
            <p>Tel: <?php echo htmlspecialchars($conf['telefono_whatsapp']); ?></p>
            
            <div class="linea"></div>
            <p>Ticket: #<?php echo str_pad($venta['id'], 6, '0', STR_PAD_LEFT); ?></p>
            <p>Fecha: <?php echo date('d/m/Y H:i', strtotime($venta['fecha'])); ?></p>
            <p>Cajero: <?php echo strtoupper($venta['usuario']); ?></p>
        </div>
        
        <div class="linea"></div>
        
        <div>
            Cliente: <?php echo htmlspecialchars(substr($venta['cliente'], 0, 25)); ?><br>
            <?php 
                // SOLUCIÓN DNI: Solo muestra si no es genérico
                if(!empty($venta['dni']) && $venta['dni'] !== '00000000') {
                    echo "DNI/CUIT: " . htmlspecialchars($venta['dni']);
                }
            ?>
        </div>
        
        <div class="linea"></div>
        
        <table>
            <thead>
                <tr>
                    <th class="cantidad" style="text-align:left;">Cant</th>
                    <th style="text-align:left;">Producto</th>
                    <th class="precio">Subt</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($detalles as $d): ?>
                <tr>
                    <td class="cantidad"><?php echo floatval($d['cantidad']); ?></td>
                    <td>
    <?php echo htmlspecialchars(substr($d['descripcion'], 0, 20)); ?>
    <?php // CAMBIO: Corregimos a precio_historico para que solo tache si el precio cobrado es menor al regular ?>
    <?php if($d['precio_regular'] > 0 && $d['precio_historico'] < $d['precio_regular']): ?>
        <br><span style="font-size:9px; color:#555;">(Antes: <s>$<?php echo number_format($d['precio_regular'], 0, ',', '.'); ?></s>)</span>
    <?php endif; ?>
</td>



                    <td class="precio">$<?php echo number_format($d['subtotal'], 2, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="linea"></div>
        
        <div class="derecha totales">
            <p>Subtotal: $<?php echo number_format($subtotal_real_productos, 2, ',', '.'); ?></p>

            <?php if($venta['descuento_monto_cupon'] > 0): ?>
                <p>Desc. Cupón: -$<?php echo number_format($venta['descuento_monto_cupon'], 2, ',', '.'); ?></p>
            <?php endif; ?>
            
            <?php if($venta['descuento_manual'] > 0): ?>
                <p>Desc. Manual: -$<?php echo number_format($venta['descuento_manual'], 2, ',', '.'); ?></p>
            <?php endif; ?>

            <?php if($saldo_favor_usado > 0): ?>
                <p>Saldo Favor: -$<?php echo number_format($saldo_favor_usado, 2, ',', '.'); ?></p>
            <?php endif; ?>

            <?php if($monto_deuda_pagado > 0): ?>
                <p>Cobro Deuda: $<?php echo number_format($monto_deuda_pagado, 2, ',', '.'); ?></p>
            <?php endif; ?>

            <p class="negrita" style="font-size: 15px; margin-top: 5px;">TOTAL: $<?php echo number_format($venta['total'], 2, ',', '.'); ?></p>
            
            <p style="font-size: 11px; margin-top:2px;">Metodo: <?php echo strtoupper(str_replace('_', ' ', $venta['metodo_pago'])); ?></p>

            <?php if($ahorro_final_cliente > 0): ?>
                <div class="ahorro-box negrita">
                    ¡USTED AHORRÓ: $<?php echo number_format($ahorro_final_cliente, 2, ',', '.'); ?>!
                </div>
            <?php endif; ?>
        </div>

        <?php if($venta['dni'] !== '00000000'): ?>
        <div class="linea"></div>
        <div class="centrado">
            <p class="negrita">PUNTOS ACUMULADOS: <?php echo $venta['puntos_acumulados'] ?? 0; ?></p>
        </div>
        <?php endif; ?>

        <div class="linea"></div>

        <div class="centrado" style="margin-top:5px;">
            <p class="negrita" style="font-size:10px;">¡REGISTRATE Y SUMÁ PUNTOS!</p>
            <img src="https://api.qrserver.com/v1/create-qr-code/?size=100x100&data=http://<?php echo $_SERVER['HTTP_HOST']; ?>/registro_cliente.php?ref_venta=<?php echo $venta['id']; ?>" alt="QR Registro" style="width:80px; height:80px; margin: 3px 0;">
            
            <?php if(!empty($conf['whatsapp_pedidos'])): ?>
                <p style="font-size:9px; margin-top:5px;">¿Hacer un pedido? WhatsApp:</p>
                <p class="negrita" style="font-size:10px;"><?php echo htmlspecialchars($conf['whatsapp_pedidos']); ?></p>
            <?php endif; ?>
        </div>
        
        <div class="linea"></div>
        <div class="centrado" style="margin-bottom: 10px;">
            <p class="negrita"><?php echo htmlspecialchars($conf['mensaje_ticket'] ?? '¡Gracias por su compra!'); ?></p>
            <p><?php echo htmlspecialchars($conf['nombre_negocio']); ?></p>
        </div>

        <button class="no-print" style="width:100%; padding:10px; margin-top:10px; cursor:pointer;" onclick="window.close()">CERRAR VENTANA</button>
    </div>
</body>
</html>
