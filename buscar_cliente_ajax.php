<?php
// buscar_cliente_ajax.php - BUSCADOR "EL 10" (FETCH_OBJ COMPLIANT)
ob_start(); // Inicia el buffer para evitar que espacios o errores rompan el JSON

// 1. Conexión con ruta garantizada desde /acciones/
$rutas_db = ['../db.php', '../includes/db.php', 'db.php', 'includes/db.php'];
$conexion = null;
foreach ($rutas_db as $ruta) {
    if (file_exists($ruta)) {
        require_once $ruta;
        break;
    }
}

// Limpiamos cualquier texto que haya podido soltar db.php (espacios, warnings)
ob_clean();
header('Content-Type: application/json; charset=utf-8');

if (!$conexion) {
    echo json_encode([]);
    exit;
}

$conf_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$rubro_actual = $conf_rubro['tipo_negocio'] ?? 'kiosco';

$term = $_GET['term'] ?? '';

if (strlen($term) > 0) {
    $term = trim($term);
    $like = "%$term%";
    
    // 2. Buscamos respetando FETCH_OBJ. Buscamos en ambas columnas por las dudas.
    $stmt = $conexion->prepare("SELECT id, nombre, dni_cuit, puntos_acumulados, saldo_favor, 
                                (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = clientes.id AND tipo = 'debe') - 
                                (SELECT COALESCE(SUM(monto),0) FROM movimientos_cc WHERE id_cliente = clientes.id AND tipo = 'haber') as saldo_calculado
                                FROM clientes 
                                WHERE (nombre LIKE ? OR dni_cuit LIKE ?) AND (tipo_negocio = ? OR tipo_negocio IS NULL)
                                LIMIT 10");
    $stmt->execute([$like, $like, $rubro_actual]);
    $clientes = $stmt->fetchAll(); 
    
    // LIMPIEZA CRÍTICA: Evita que cualquier espacio o error rompa el buscador
    if (ob_get_length()) ob_clean(); 
    header('Content-Type: application/json; charset=utf-8');

    $resultados = [];
    foreach($clientes as $c) {
        $resultados[] = [
            'id'     => $c->id,
            'label'  => $c->nombre,
            'nombre' => $c->nombre,
            'dni'    => $c->dni_cuit ?: 'S/DNI',
            'puntos' => number_format($c->puntos_acumulados, 0, '', ''),
            'saldo'  => number_format($c->saldo_calculado, 2, '.', '')
        ];
    }
    echo json_encode($resultados);
} else {
    echo json_encode([]);
}
exit;