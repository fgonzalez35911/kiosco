<?php
// acciones/subir_foto_rapida.php
require_once '../includes/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = $_POST['id_producto'];
    $data = $_POST['imagen_base64'];

    if(empty($id) || empty($data)) {
        echo json_encode(['status'=>'error', 'msg'=>'Datos faltantes']);
        exit;
    }

    // Procesar Base64
    $image_array_1 = explode(";", $data);
    $image_array_2 = explode(",", $image_array_1[1]);
    $data = base64_decode($image_array_2[1]);

    // Crear nombre único
    $nombre_img = 'prod_' . time() . '_' . rand(100,999) . '.png';
    // Asegúrate de que la carpeta uploads exista en la raíz o ajusta la ruta
    $ruta_destino = '../uploads/' . $nombre_img;
    $ruta_db = 'uploads/' . $nombre_img; // Ruta relativa para la BD

    if (!is_dir('../uploads')) mkdir('../uploads', 0777, true);

    if(file_put_contents($ruta_destino, $data)) {
        // Actualizar BD
        $stmt = $conexion->prepare("UPDATE productos SET imagen_url = ? WHERE id = ?");
        $stmt->execute([$ruta_db, $id]);
        
        echo json_encode(['status'=>'success', 'url'=>$ruta_db, 'msg'=>'Foto actualizada']);
    } else {
        echo json_encode(['status'=>'error', 'msg'=>'Error al guardar archivo']);
    }
}
?>