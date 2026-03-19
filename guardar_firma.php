<?php
// acciones/guardar_firma.php
session_start();
require_once '../includes/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id']) || !isset($_POST['imgBase64'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Datos incompletos']);
    exit;
}

$id_usuario = $_SESSION['usuario_id'];
$rol = $_SESSION['rol'];
$img = $_POST['imgBase64'];

// 1. Limpiar el string base64
$img = str_replace('data:image/png;base64,', '', $img);
$img = str_replace(' ', '+', $img);
$data = base64_decode($img);

// 2. Definir ruta y nombre de archivo
$directorio = '../img/firmas/';

// Si no existe la carpeta, la creamos
if (!file_exists($directorio)) {
    mkdir($directorio, 0777, true);
}

// LÓGICA DE NOMBRE AISLADA:
// Ahora TODOS guardan su firma por su ID único, así no se pisan entre Dueño y Admin.
$archivo = $directorio . 'usuario_' . $id_usuario . '.png';

// 3. Guardar el archivo
if (file_put_contents($archivo, $data)) {
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Error de escritura']);
}
?>