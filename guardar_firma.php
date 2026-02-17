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

// LÓGICA DE NOMBRE:
// Si es SuperAdmin (Rol 1) o Dueño (Rol 2), guardamos como "firma_admin.png" para que salga a la derecha.
// Si es Empleado (Rol 3), guardamos como "usuario_ID.png" para que salga a la izquierda.
// (Esto sobreescribe la firma anterior si ya existe, lo cual es correcto)

if ($rol <= 2) {
    $archivo = $directorio . 'firma_admin.png';
} else {
    $archivo = $directorio . 'usuario_' . $id_usuario . '.png';
}

// 3. Guardar el archivo
if (file_put_contents($archivo, $data)) {
    echo json_encode(['status' => 'success']);
} else {
    echo json_encode(['status' => 'error', 'msg' => 'Error de escritura']);
}
?>