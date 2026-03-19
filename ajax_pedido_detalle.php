<?php
require_once 'includes/db.php';
$id = intval($_GET['id']);

// Se agrega p.tipo a la consulta para saber si es un combo
$stmt = $conexion->prepare("SELECT d.*, p.descripcion, p.tipo, p.stock_actual FROM pedidos_whatsapp_detalle d JOIN productos p ON d.id_producto = p.id WHERE d.id_pedido = ?");
$stmt->execute([$id]);
$items = $stmt->fetchAll(PDO::FETCH_OBJ);

echo '<table class="table table-sm table-bordered text-start">';
echo '<thead class="table-light"><tr><th>Producto</th><th class="text-center">Cant</th><th class="text-end">Precio</th><th class="text-center">Stock Físico</th></tr></thead><tbody>';
foreach ($items as $i) {
    $cantidad_limpia = floatval($i->cantidad);
    
    // Si es un combo, le ponemos la etiqueta COMBO, si no, mostramos el número limpio
    if ($i->tipo === 'combo') {
        $stock_limpio = 'COMBO';
    } else {
        $stock_limpio = floatval($i->stock_actual);
    }
    
    echo "<tr>
            <td class='fw-bold'>{$i->descripcion}</td>
            <td class='text-center'>{$cantidad_limpia}</td>
            <td class='text-end text-success fw-bold'>$" . number_format($i->precio_unitario, 2) . "</td>
            <td class='text-center'><span class='badge bg-info text-dark'>{$stock_limpio}</span></td>
          </tr>";
}
echo '</tbody></table>';
?>