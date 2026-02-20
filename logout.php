<?php
session_start();

// 1. CONEXIÓN
if (file_exists('includes/db.php')) { require_once 'includes/db.php'; } 
elseif (file_exists('db.php')) { require_once 'db.php'; } 

// 2. AUDITORÍA: LOGOUT
if (isset($_SESSION['usuario_id'])) {
    $user_id = $_SESSION['usuario_id'];
    try {
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'LOGOUT', 'Cierre de sesión voluntario', NOW())")->execute([$user_id]);
    } catch (Exception $e) { }
}

session_destroy();
header("Location: index.php");
exit;
?>