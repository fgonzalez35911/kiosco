<?php
// acciones/listar_rapidos.php
require_once '../includes/db.php';

$cat = $_GET['cat'] ?? '';

if(!empty($cat)) {
    $stmt = $conexion->prepare("SELECT * FROM productos WHERE id_categoria = ? AND activo = 1 LIMIT 12");
    $stmt->execute([$cat]);
} else {
    // Productos destacados o random
    $stmt = $conexion->query("SELECT * FROM productos WHERE activo = 1 ORDER BY stock_actual DESC LIMIT 12");
}

echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>