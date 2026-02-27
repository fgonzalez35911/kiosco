<?php
// generar_imagen_ia.php - MOTOR IA + AUTO-RECORTE PNG TRANSPARENTE
session_start();
header('Content-Type: application/json');

$prompt_original = $_POST['prompt'] ?? '';
if (empty($prompt_original)) { echo json_encode(['status' => 'error', 'msg' => 'Nombre vacío']); exit; }

// 1. LIMPIEZA DE PROMPT (Para evitar Cristos y árboles)
// Quitamos términos comerciales que confunden a la IA
$basura = ["de ombligo", "por kilo", "en oferta", "oferta", "kilo", "kg", "fresca", "fresco"];
$limpio = str_ireplace($basura, "", $prompt_original);
$limpio = trim($limpio);

// 2. TRADUCCIÓN RÁPIDA (Flux entiende mejor "Orange fruit" que "Naranja")
$mapa = ['naranja' => 'orange fruit', 'papa' => 'potato', 'tomate' => 'tomato', 'cebolla' => 'onion'];
$prompt_en = isset($mapa[strtolower($limpio)]) ? $mapa[strtolower($limpio)] : $limpio;

// Prompt ultra-específico para evitar fondos raros
$final_prompt = urlencode("One single " . $prompt_en . ", professional product photography, centered, isolated on pure white background, 8k, sharp focus");

// 3. GENERACIÓN CON IA (FLUX TURBO)
$url_ia = "https://image.pollinations.ai/prompt/{$final_prompt}?width=800&height=800&nologo=true&model=flux&seed=" . rand(1, 99999);

$ch = curl_init($url_ia);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_TIMEOUT, 40); 
$image_data = curl_exec($ch);
curl_close($ch);

if (!empty($image_data)) {
    $src = @imagecreatefromstring($image_data);
    if ($src) {
        $w = imagesx($src);
        $h = imagesy($src);
        
        // 4. EL TRUCO DEL PNG: CREAR TRANSPARENCIA
        $dst = imagecreatetruecolor($w, $h);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        
        // Creamos un color transparente
        $trans_colour = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $trans_colour);
        
        // Recorremos la imagen y todo lo que sea "casi blanco" lo hacemos transparente
        for ($x = 0; $x < $w; $x++) {
            for ($y = 0; $y < $h; $y++) {
                $rgb = imagecolorat($src, $x, $y);
                $colors = imagecolorsforindex($src, $rgb);
                
                // Si el píxel es muy blanco (R, G y B > 240), lo hacemos transparente
                if ($colors['red'] > 240 && $colors['green'] > 240 && $colors['blue'] > 240) {
                    imagesetpixel($dst, $x, $y, $trans_colour);
                } else {
                    $pixel_color = imagecolorallocatealpha($dst, $colors['red'], $colors['green'], $colors['blue'], 0);
                    imagesetpixel($dst, $x, $y, $pixel_color);
                }
            }
        }

        if (!is_dir('uploads')) mkdir('uploads', 0777, true);
        $nombre = 'prod_trans_' . time() . '.png';
        $ruta = 'uploads/' . $nombre;
        
        imagepng($dst, $ruta);
        imagedestroy($src); imagedestroy($dst);

        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https://" : "http://";
        $url_final = $protocol . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['PHP_SELF']), '/\\') . '/' . $ruta;
        
        echo json_encode(['status' => 'success', 'imagen_url' => $url_final, 'ruta_db' => $ruta]);
        exit;
    }
}

echo json_encode(['status' => 'error', 'msg' => 'La IA está saturada. Intenta de nuevo.']);