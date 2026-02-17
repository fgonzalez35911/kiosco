<?php
// buscar_cliente_ajax.php - BUSCADOR INTELIGENTE JSON
// Devuelve resultados para el autocompletado en canje_puntos.php

// 1. Conexión a prueba de fallos
$rutas_db = [__DIR__ . '/db.php', __DIR__ . '/includes/db.php', 'db.php', '../db.php'];
$conexion = null;
foreach ($rutas_db as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        break;
    }
}

if (!$conexion) {
    echo json_encode([]);
    exit;
}

$term = $_GET['term'] ?? '';

if (strlen($term) > 0) {
    $term = trim($term);
    $like = "%$term%";
    
    // Buscamos por Nombre, DNI o CUIT
    $stmt = $conexion->prepare("SELECT id, nombre, dni, dni_cuit, puntos_acumulados, saldo_favor, 
                                (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = clientes.id AND tipo = 'debe') - 
                                (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = clientes.id AND tipo = 'haber') as saldo_calculado
                                FROM clientes 
                                WHERE nombre LIKE ? OR dni LIKE ? OR dni_cuit LIKE ? 
                                LIMIT 10");
    $stmt->execute([$like, $like, $like]);
    $clientes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Formateamos para el frontend
    $resultados = [];
    foreach($clientes as $c) {
        $resultados[] = [
            'id' => $c['id'],
            'label' => $c['nombre'], // Para UI standard
            'nombre' => $c['nombre'],
            'dni' => $c['dni'] ?: $c['dni_cuit'] ?: 'S/DNI',
            'puntos' => number_format($c['puntos_acumulados'], 0),
            'saldo' => number_format($c['saldo_calculado'], 0)
        ];
    }
    
    header('Content-Type: application/json');
    echo json_encode($resultados);
} else {
    echo json_encode([]);
}
?>
