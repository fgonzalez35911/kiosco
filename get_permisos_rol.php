<?php
// acciones/get_permisos_rol.php
require_once '../includes/db.php';
$id = $_GET['id'] ?? 0;
$stmt = $conexion->prepare("SELECT id_permiso FROM rol_permisos WHERE id_rol = ?");
$stmt->execute([$id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_COLUMN));
?>