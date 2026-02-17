<?php
session_start();
require_once '../includes/db.php';

// Solo Admin puede hacer esto (asumiendo rol 1 = Admin)
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] > 1) { header("Location: ../index.php"); exit; }

$id = $_GET['id'];
$accion = $_GET['accion'];
$estado = ($accion == 'bloquear') ? 1 : 0;

$conexion->prepare("UPDATE usuarios SET forzar_logout = ? WHERE id = ?")->execute([$estado, $id]);

// Auditoría
$det = "Usuario ID $id " . strtoupper($accion) . " remotamente.";
$conexion->prepare("INSERT INTO auditoria (fecha, id_usuario, accion, detalles) VALUES (NOW(), ?, 'SEGURIDAD', ?)")
         ->execute([$_SESSION['usuario_id'], $det]);

header("Location: ../usuarios.php");
exit;
?>
