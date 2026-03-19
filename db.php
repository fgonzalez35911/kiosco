<?php
// includes/db.php - CORRECCIÓN UTF-8 Y ZONA HORARIA
$host = "localhost"; 
$usuario = "u415354546_kiosco"; 
$password = "Brg13abr"; 
$base_datos = "u415354546_kiosco"; 

try {
    $conexion = new PDO("mysql:host=$host;dbname=$base_datos;charset=utf8mb4", $usuario, $password);
    // En desarrollo usa ERRMODE_EXCEPTION, en producción usa ERRMODE_SILENT
    $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);
    $conexion->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
    
    // Forzamos UTF-8 real
    $conexion->exec("SET NAMES 'utf8mb4'"); 
    
    // ZONA HORARIA ARGENTINA PARA LA BASE DE DATOS
    $conexion->exec("SET time_zone = '-03:00'"); 
    
} catch (PDOException $e) {
    die("Error crítico: " . $e->getMessage());
}

// ZONA HORARIA ARGENTINA PARA PHP
date_default_timezone_set('America/Argentina/Buenos_Aires');
setlocale(LC_TIME, 'es_AR.UTF-8', 'es_AR', 'esp');
?>