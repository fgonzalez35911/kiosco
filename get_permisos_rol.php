<?php
// get_permisos_rol.php - VERSIÓN BLINDADA
// Este archivo devuelve los permisos en formato JSON para el modal de roles.php

// 1. Buscador inteligente de db.php (Evita error 500)
$rutas_db = [
    __DIR__ . '/db.php', 
    __DIR__ . '/includes/db.php', 
    'db.php', 
    'includes/db.php',
    '../db.php'
];

$conexion_ok = false;
foreach ($rutas_db as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        $conexion_ok = true;
        break;
    }
}

// Si falla la conexión, devolvemos array vacío pero JSON válido (evita el error en pantalla)
if (!$conexion_ok || !isset($conexion)) {
    echo json_encode([]); 
    exit;
}

header('Content-Type: application/json');

$id = $_GET['id'] ?? 0;
$ids_permisos = [];

if ($id > 0) {
    try {
        $stmt = $conexion->prepare("SELECT id_permiso FROM rol_permisos WHERE id_rol = ?");
        $stmt->execute([$id]);
        $ids_permisos = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        // En caso de error SQL, devolvemos vacío
        $ids_permisos = [];
    }
}

echo json_encode($ids_permisos);
?>
