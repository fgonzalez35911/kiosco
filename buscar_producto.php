<?php
// acciones/buscar_producto.php
require_once '../includes/db.php';

// Limpieza de buffer por si db.php mete ruido
ob_start();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

$term = $_GET['term'] ?? '';

if(strlen($term) > 0) {
    $coincidencia = "%" . $term . "%";
    
    // [CAMBIO] Hacemos JOIN con combos para saber si es ilimitado o tiene fechas
    $sql = "SELECT p.*, c.es_ilimitado, c.fecha_inicio, c.fecha_fin 
            FROM productos p 
            LEFT JOIN combos c ON p.codigo_barras = c.codigo_barras 
            WHERE (p.descripcion LIKE ? OR p.codigo_barras LIKE ?) 
            AND p.activo = 1 
            LIMIT 15"; // Subí a 15 para que tengas más margen
            
    $stmt = $conexion->prepare($sql);
    $stmt->execute([$coincidencia, $coincidencia]);
    $productos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if($productos) {
        echo json_encode(['status' => 'success', 'data' => $productos]);
    } else {
        echo json_encode(['status' => 'error']);
    }
} else {
    echo json_encode(['status' => 'error']);
}
?>