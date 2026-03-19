<?php
// credencial_pdf.php - DISEÑO ARGENTINA (SEPARACIÓN DE ROL AJUSTADA)
session_start();
require_once 'includes/db.php';

$id_usuario = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($id_usuario == 0) { die("Error: ID Inválido."); }

$stmt = $conexion->prepare("SELECT u.*, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id WHERE u.id = ?");
$stmt->execute([$id_usuario]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u) { die("Error: Empleado no encontrado."); }

$protocolo = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$fecha_str = !empty($u['fecha_creacion']) ? $u['fecha_creacion'] : 'S/D';
$token_seguridad = base64_encode($u['id'] . "-DrUgStOrE-" . $fecha_str);
$link_validacion = $protocolo . "://" . $_SERVER['HTTP_HOST'] . "/validar_credencial.php?id=" . $u['id'] . "&token=" . $token_seguridad;
$qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&margin=0&data=" . urlencode($link_validacion);


$foto = !empty($u['foto_perfil']) ? 'uploads/'.$u['foto_perfil'] : 'img/no-image.png';

$is_raw = isset($_GET['raw']) && $_GET['raw'] == '1';

// --- DATOS DE LA AUTORIDAD (DUEÑO) Y EMISIÓN ---
$stmtFirma = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id ORDER BY r.id ASC LIMIT 1");
$autoridad = $stmtFirma ? $stmtFirma->fetch(PDO::FETCH_ASSOC) : false;
$id_autoridad = $autoridad ? $autoridad['id'] : null;

$nombre_autoridad = $autoridad ? strtoupper($autoridad['nombre_completo']) : 'GERENCIA';
$rol_autoridad = $autoridad ? strtoupper($autoridad['nombre_rol']) : 'DIRECCIÓN';
$ruta_firma_autoridad = $id_autoridad && file_exists("img/firmas/usuario_{$id_autoridad}.png") ? "img/firmas/usuario_{$id_autoridad}.png" : "img/firmas/firma_admin.png";

// Fecha de validación
$fecha_emision = !empty($u['fecha_creacion']) ? date('d/m/Y', strtotime($u['fecha_creacion'])) : date('d/m/Y');

// --- DATOS FULL DEL PERFIL ---
$dni = $u['dni'] ?: 'S/D';
$cuil = $u['cuil'] ?: 'S/D';
$nacimiento = !empty($u['fecha_nacimiento']) ? date('d/m/Y', strtotime($u['fecha_nacimiento'])) : 'S/D';
$talla = $u['talla_uniforme'] ?: 'S/D';
$estado_civil = $u['estado_civil'] ?: 'S/D';
$tel = $u['whatsapp'] ?: 'S/D';
$email = $u['email'] ?: 'S/D';
$cp = $u['codigo_postal'] ?: '';
$domicilio = ($u['domicilio'] ?: 'S/D') . ($cp ? " (CP $cp)" : "");
$sangre = $u['grupo_sanguineo'] ?: 'S/D';
$alergias = !empty($u['alergias']) ? strtoupper($u['alergias']) : 'NINGUNA';
$emergencia = $u['contacto_emergencia'] ?: 'S/D';

// --- PATRÓN DE ÍCONOS EXAGERADOS ---
$iconos_bg = '';
$iconos_array = ['🧉', '💊', '🛒', '🇦🇷', '⚽', '🏪', '⭐', '🥟', '🏥', '🥐', '🥩', '🍷', '🎸', '🚌'];
$posiciones = [
    ['t'=>'-2', 'l'=>'5', 's'=>'6'], ['t'=>'4', 'l'=>'35', 's'=>'10'], ['t'=>'12', 'l'=>'-2', 's'=>'8'],
    ['t'=>'15', 'l'=>'45', 's'=>'5'], ['t'=>'25', 'l'=>'8', 's'=>'12'], ['t'=>'28', 'l'=>'42', 's'=>'7'],
    ['t'=>'38', 'l'=>'-2', 's'=>'9'], ['t'=>'42', 'l'=>'48', 's'=>'6'], ['t'=>'52', 'l'=>'2', 's'=>'11'],
    ['t'=>'58', 'l'=>'40', 's'=>'8'], ['t'=>'68', 'l'=>'-1', 's'=>'5'], ['t'=>'72', 'l'=>'45', 's'=>'10'],
    ['t'=>'80', 'l'=>'10', 's'=>'7'], ['t'=>'22', 'l'=>'38', 's'=>'9'], ['t'=>'5', 'l'=>'20', 's'=>'6'],
    ['t'=>'35', 'l'=>'25', 's'=>'8'], ['t'=>'48', 'l'=>'22', 's'=>'12'], ['t'=>'65', 'l'=>'20', 's'=>'7'],
    ['t'=>'82', 'l'=>'30', 's'=>'9'], ['t'=>'-3', 'l'=>'25', 's'=>'11'], ['t'=>'18', 'l'=>'20', 's'=>'5'],
    ['t'=>'75', 'l'=>'35', 's'=>'8'], ['t'=>'85', 'l'=>'40', 's'=>'6'], ['t'=>'10', 'l'=>'50', 's'=>'9'],
    ['t'=>'30', 'l'=>'15', 's'=>'6'], ['t'=>'55', 'l'=>'28', 's'=>'10'], ['t'=>'40', 'l'=>'15', 's'=>'5'],
    ['t'=>'85', 'l'=>'5', 's'=>'12'], ['t'=>'60', 'l'=>'10', 's'=>'8'], ['t'=>'20', 'l'=>'50', 's'=>'7']
];

foreach ($posiciones as $index => $pos) {
    $icono = $iconos_array[$index % count($iconos_array)];
    $iconos_bg .= '<div style="position: absolute; top: '.$pos['t'].'mm; left: '.$pos['l'].'mm; font-size: '.$pos['s'].'px; opacity: 0.12; z-index: 1;">'.$icono.'</div>';
}

// Estructura HTML EXACTA a 54x86mm
$tarjeta_html = '
<div id="credencial-box" style="width: 54mm; height: 86mm; background: #ffffff; position: relative; overflow: hidden; font-family: \'Arial\', sans-serif; box-sizing: border-box; margin: 0 auto; border: 1px solid #ddd;">
    
    '.$iconos_bg.'
    <img src="img/sol_de_mayo.png" style="position: absolute; top: 15mm; left: -5mm; width: 64mm; height: 64mm; opacity: 0.15; z-index: 1; object-fit: contain;" onerror="this.style.display=\'none\'">

    <div style="position: absolute; top: -20mm; left: -15mm; width: 84mm; height: 45mm; background-color: #74ACDF; border-radius: 50%; z-index: 2;"></div>
    <div style="position: absolute; bottom: -20mm; left: -15mm; width: 84mm; height: 35mm; background-color: #74ACDF; border-radius: 50%; z-index: 2;"></div>

    <div style="position: absolute; top: 3.5mm; left: 0; width: 100%; text-align: center; z-index: 10; color: #ffffff; font-size: 4.5px; font-weight: 900; letter-spacing: 1.5px; text-shadow: 0.5px 0.5px 1px rgba(0,0,0,0.3);">
        CREDENCIAL DE IDENTIFICACIÓN
    </div>

    <div style="position: absolute; top: 7.5mm; left: 0; width: 100%; text-align: center; z-index: 10;">
        <img src="'.$foto.'" style="width: 18mm; height: 18mm; border-radius: 50%; border: 1.5px solid #fff; object-fit: cover; box-shadow: 0 2px 4px rgba(0,0,0,0.2); background: #fff;">
        <div style="margin-top: 1mm; font-size: 6px; font-weight: 900; color: #000; text-transform: uppercase; letter-spacing: -0.2px; line-height: 1;">'.strtoupper($u['nombre_completo']).'</div>
        <div style="font-size: 4px; color: #555; font-weight: bold; margin-top: 0.5mm; line-height: 1;">@'.strtolower($u['usuario']).'</div>
        <div style="font-size: 4px; color: #102A57; font-weight: bold; letter-spacing: 1px; text-transform: uppercase; margin-top: 0.5mm; line-height: 1; background: rgba(255,255,255,0.8); display: inline-block; padding: 0.3mm 1.5mm; border-radius: 0.5mm;">'.strtoupper($u['nombre_rol']).'</div>
    </div>

    <div style="position: absolute; top: 33mm; left: 3mm; width: 48mm; z-index: 10;">
        <div style="background: rgba(255,255,255,0.95); border: 0.5px solid #74ACDF; border-radius: 1mm; padding: 1mm; box-shadow: 0 1px 3px rgba(0,0,0,0.1); font-size: 3.2px; line-height: 1.2; text-align: left; color: #333;">
            
            <div style="display: flex; gap: 0.5mm; margin-bottom: 0.5mm;">
                <div style="flex: 1; background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm;"><span style="font-weight: bold; color: #102A57;">ID:</span> EMP-'.str_pad($u['id'], 4, '0', STR_PAD_LEFT).'</div>
                <div style="flex: 1; background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm;"><span style="font-weight: bold; color: #102A57;">DNI:</span> '.$dni.'</div>
                <div style="flex: 1; background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm;"><span style="font-weight: bold; color: #102A57;">CUIL:</span> '.$cuil.'</div>
            </div>
            
            <div style="display: flex; gap: 0.5mm; margin-bottom: 0.5mm;">
                <div style="flex: 1; background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm;"><span style="font-weight: bold; color: #102A57;">NAC:</span> '.$nacimiento.'</div>
                <div style="flex: 1; background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm;"><span style="font-weight: bold; color: #102A57;">TALLA:</span> '.$talla.'</div>
                <div style="flex: 1; background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm;"><span style="font-weight: bold; color: #102A57;">E.C:</span> '.$estado_civil.'</div>
            </div>

            <div style="display: flex; gap: 0.5mm; margin-bottom: 0.5mm;">
                <div style="flex: 1; background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm;"><span style="font-weight: bold; color: #102A57;">TEL:</span> '.$tel.'</div>
                <div style="flex: 1.5; background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm; white-space: nowrap; overflow: hidden;"><span style="font-weight: bold; color: #102A57;">EMAIL:</span> '.$email.'</div>
            </div>

            <div style="background: #f0f8ff; padding: 0.3mm; border-radius: 0.5mm; margin-bottom: 0.5mm;">
                <span style="font-weight: bold; color: #102A57;">DOM:</span> '.$domicilio.'
            </div>

            <div style="display: flex; gap: 0.5mm; margin-bottom: 0.5mm; color: #d32f2f;">
                <div style="width: 30%; background: #ffebee; padding: 0.3mm; border-radius: 0.5mm;"><span style="font-weight: bold;">GS:</span> '.$sangre.'</div>
                <div style="width: 70%; background: #ffebee; padding: 0.3mm; border-radius: 0.5mm; white-space: nowrap; overflow: hidden;"><span style="font-weight: bold;">ALERGIAS:</span> '.$alergias.'</div>
            </div>

            <div style="background: #ffebee; padding: 0.3mm; border-radius: 0.5mm; color: #d32f2f;">
                <span style="font-weight: bold;">EMERGENCIA:</span> '.$emergencia.'
            </div>

        </div>
    </div>

    <div style="position: absolute; bottom: 18mm; left: 0; width: 100%; text-align: center; z-index: 10;">
        <div style="font-size: 3.2px; color: #333; font-weight: bold; margin-bottom: 0.5mm;">VALIDADA DESDE: '.$fecha_emision.'</div>
        
        '.(file_exists($ruta_firma_autoridad) ? '<img src="'.$ruta_firma_autoridad.'" style="max-height: 16mm; position: relative; margin-bottom: -3.5mm; z-index: 2;">' : '<div style="height: 16mm; margin-bottom: -3.5mm;"></div>').'
        
        <div style="border-top: 1px solid #102A57; width: 38mm; margin: 0 auto; position: relative; z-index: 1;"></div>
        
        <div style="font-size: 5px; font-weight: 900; color: #102A57; padding-top: 0.8mm; line-height: 1; margin-bottom: 0;">'.$nombre_autoridad.'</div>
        <div style="margin-top: -3.0mm;"><span style="font-size: 3.5px; color: #fff; background: #102A57; display: inline-block; padding: 0.2mm 2mm; border-radius: 0.5mm; letter-spacing: 0.5px; font-weight: bold; line-height: 1;">'.$rol_autoridad.'</span></div>
    </div>

    <img src="img/malvinas.png" style="position: absolute; bottom: 1.5mm; left: 3mm; width: 12mm; height: auto; opacity: 0.9; z-index: 10;" onerror="this.style.display=\'none\'">
    
    <div style="position: absolute; bottom: 1.5mm; left: 50%; transform: translateX(-50%); text-align: center; z-index: 10;">
        <img src="'.$qrUrl.'" style="width: 11mm; height: 11mm; background: #fff; padding: 0.5mm; border-radius: 1mm; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
    </div>

    <div style="position: absolute; bottom: 2.5mm; right: 3mm; display: flex; align-items: flex-end; justify-content: center; z-index: 10; opacity: 0.9; width: 12mm;">
        <img src="img/estrella.png" style="width: 3.5mm; height: auto; margin-bottom: 0.8mm; margin-right: -0.8mm; z-index: 1;" onerror="this.style.display=\'none\'">
        <img src="img/estrella.png" style="width: 5.5mm; height: auto; z-index: 2;" onerror="this.style.display=\'none\'">
        <img src="img/estrella.png" style="width: 3.5mm; height: auto; margin-bottom: 0.8mm; margin-left: -0.8mm; z-index: 1;" onerror="this.style.display=\'none\'">
    </div>

</div>';

if ($is_raw) {
    echo $tarjeta_html;
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Credencial - <?php echo $u['nombre_completo']; ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
    <style>
        body { background-color: #e9ecef; display: flex; flex-direction: column; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 20px; }
        .btn-download { margin-bottom: 20px; background: #74ACDF; color: white; border: 2px solid #fff; padding: 12px 25px; border-radius: 50px; font-weight: bold; cursor: pointer; text-transform: uppercase; box-shadow: 0 4px 10px rgba(0,0,0,0.3); text-shadow: 1px 1px 2px rgba(0,0,0,0.2); }
        .wrapper { border: 1px dashed #7f8c8d; padding: 10px; background: #fff; border-radius: 5px; }
    </style>
</head>
<body>

    <button class="btn-download" onclick="descargarPDF()">📥 DESCARGAR CREDENCIAL ARGENTINA</button>
    
    <div class="wrapper">
        <?php echo $tarjeta_html; ?>
    </div>

    <script>
        function descargarPDF() {
            const element = document.getElementById('credencial-box');
            
            const opt = {
                margin:       0,
                filename:     'Credencial_<?php echo str_pad($u['id'], 4, '0', STR_PAD_LEFT); ?>.pdf',
                image:        { type: 'jpeg', quality: 1 },
                html2canvas:  { scale: 4, useCORS: true, width: 204, height: 325 }, 
                jsPDF:        { unit: 'mm', format: [54, 86], orientation: 'portrait' }
            };
            
            html2pdf().set(opt).from(element).save();
        }
    </script>
</body>
</html>
