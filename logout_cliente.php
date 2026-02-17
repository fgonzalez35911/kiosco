<?php
// logout_cliente.php - CERRAR SESIÓN DEL CLIENTE
session_start();

// Eliminamos solo las variables de cliente para no sacar al administrador si está logueado en la misma PC
if(isset($_SESSION['cliente_id'])) unset($_SESSION['cliente_id']);
if(isset($_SESSION['cliente_nombre'])) unset($_SESSION['cliente_nombre']);
if(isset($_SESSION['cliente_puntos'])) unset($_SESSION['cliente_puntos']);

// Redirigir a la tienda
header("Location: tienda.php");
exit;
?>