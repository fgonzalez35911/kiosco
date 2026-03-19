<?php
// validar_credencial.php - PORTAL PÚBLICO CON PEAJE, GPS Y DETECTIVE DE HARDWARE
session_start(); 

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'libs/PHPMailer/src/Exception.php';
require 'libs/PHPMailer/src/PHPMailer.php';
require 'libs/PHPMailer/src/SMTP.php';
require_once 'includes/db.php';

// Guardamos TODOS los datos en la memoria
if (isset($_POST['gps_checked'])) {
    $_SESSION['gps_checked'] = true;
    $_SESSION['lat'] = htmlspecialchars($_POST['lat'] ?? 'Desconocida');
    $_SESSION['lon'] = htmlspecialchars($_POST['lon'] ?? 'Desconocida');
    $_SESSION['resolucion'] = htmlspecialchars($_POST['resolucion'] ?? 'N/A');
    $_SESSION['nucleos'] = htmlspecialchars($_POST['nucleos'] ?? 'N/A');
    $_SESSION['idioma'] = htmlspecialchars($_POST['idioma'] ?? 'N/A');
    $_SESSION['zona'] = htmlspecialchars($_POST['zona'] ?? 'N/A');
}

$id_usuario = isset($_GET['id']) ? intval($_GET['id']) : 0;
$token_recibido = isset($_GET['token']) ? str_replace(' ', '+', $_GET['token']) : '';

// =========================================================================
// PASO 1: EL PEAJE. 
// =========================================================================
if (!isset($_SESSION['gps_checked'])) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Verificando Credencial...</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <style>
            body { background-color: #f0f2f5; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; font-family: 'Arial', sans-serif; }
            .loader-box { text-align: center; background: white; padding: 40px 30px; border-radius: 15px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); max-width: 350px; width: 90%; border: 1px solid #ddd; }
            .contador-numero { font-size: 1.5rem; font-weight: 900; color: #d32f2f; }
        </style>
    </head>
    <body>
        <div class="loader-box">
            <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;"></div>
            <h5 class="fw-bold text-dark text-uppercase">Validación de Seguridad</h5>
            
            <p class="text-muted small mb-2 mt-2">Para visualizar esta credencial oficial, el sistema requiere confirmar su ubicación actual de forma obligatoria.</p>
            
            <div class="alert alert-warning p-2 mb-0 mt-3" style="font-size: 0.85rem; border-left: 4px solid #ff9800;">
                A continuación, su dispositivo le solicitará acceso al GPS.<br>
                <strong>Debe presionar "PERMITIR".</strong>
            </div>
            
            <p class="text-primary small mt-3 mb-0" id="mensaje-espera">
                Solicitando permisos en <span id="contador" class="contador-numero">10</span> segundos...
            </p>
        </div>

        <form id="gpsForm" method="POST" action="validar_credencial.php?id=<?php echo $id_usuario; ?>&token=<?php echo urlencode($token_recibido); ?>">
            <input type="hidden" name="gps_checked" value="1">
            <input type="hidden" name="lat" id="lat" value="Desconocida">
            <input type="hidden" name="lon" id="lon" value="Desconocida">
            <input type="hidden" name="resolucion" id="resolucion" value="">
            <input type="hidden" name="nucleos" id="nucleos" value="">
            <input type="hidden" name="idioma" id="idioma" value="">
            <input type="hidden" name="zona" id="zona" value="">
        </form>

        <script>
            // Llenamos el formulario con los datos forenses
            document.getElementById('resolucion').value = window.screen.width + 'x' + window.screen.height;
            document.getElementById('nucleos').value = navigator.hardwareConcurrency || 'Oculto por navegador';
            document.getElementById('idioma').value = navigator.language || 'Desconocido';
            document.getElementById('zona').value = Intl.DateTimeFormat().resolvedOptions().timeZone || 'Desconocido';

            function avanzarAlResultado(latitud, longitud) {
                document.getElementById('lat').value = latitud;
                document.getElementById('lon').value = longitud;
                document.getElementById('gpsForm').submit();
            }

            function pedirUbicacion() {
                if ("geolocation" in navigator) {
                    navigator.geolocation.getCurrentPosition(
                        function(position) {
                            avanzarAlResultado(position.coords.latitude, position.coords.longitude);
                        },
                        function(error) {
                            avanzarAlResultado('Denegado', 'Denegado');
                        },
                        { enableHighAccuracy: true, timeout: 8000, maximumAge: 0 }
                    );
                } else {
                    avanzarAlResultado('No Soportado', 'No Soportado');
                }
            }

            let tiempo = 10;
            let temporizador = setInterval(function() {
                tiempo--;
                document.getElementById('contador').innerText = tiempo;
                
                if (tiempo <= 0) {
                    clearInterval(temporizador);
                    document.getElementById('mensaje-espera').innerHTML = "<strong style='color:#d32f2f;'>⏳ Esperando respuesta...</strong>";
                    pedirUbicacion();
                }
            }, 1000);
        </script>
    </body>
    </html>
    <?php
    exit; 
}

// =========================================================================
// PASO 2: EL RESULTADO.
// =========================================================================

// Rescatamos los datos forenses de la memoria
$lat_obtenida = $_SESSION['lat'] ?? 'Desconocida';
$lon_obtenida = $_SESSION['lon'] ?? 'Desconocida';
$resolucion_obtenida = $_SESSION['resolucion'] ?? 'N/A';
$nucleos_obtenidos = $_SESSION['nucleos'] ?? 'N/A';
$idioma_obtenido = $_SESSION['idioma'] ?? 'N/A';
$zona_obtenida = $_SESSION['zona'] ?? 'N/A';

$u = false;
if ($id_usuario > 0) {
    $stmt = $conexion->prepare("SELECT u.*, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id WHERE u.id = ?");
    $stmt->execute([$id_usuario]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);
}

$conf = $conexion->query("SELECT nombre_negocio, logo_url FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$nombre_negocio = $conf['nombre_negocio'] ?? 'EMPRESA';
$logo = (!empty($conf['logo_url'])) ? $conf['logo_url'] : '';

function obtenerDispositivoLimpio($user_agent) {
    $os = 'Desconocido'; $browser = 'Desconocido';
    if (preg_match('/android/i', $user_agent)) { $os = 'Android (Smartphone/Tablet)'; }
    elseif (preg_match('/iphone/i', $user_agent)) { $os = 'iPhone (iOS)'; }
    elseif (preg_match('/ipad/i', $user_agent)) { $os = 'iPad (iOS)'; }
    elseif (preg_match('/windows nt 10/i', $user_agent)) { $os = 'Windows 10/11 (PC)'; }
    elseif (preg_match('/macintosh|mac os x/i', $user_agent)) { $os = 'Mac (Apple PC)'; }
    if (preg_match('/edg/i', $user_agent)) { $browser = 'Edge'; }
    elseif (preg_match('/chrome/i', $user_agent)) { $browser = 'Chrome'; }
    elseif (preg_match('/safari/i', $user_agent) && !preg_match('/chrome/i', $user_agent)) { $browser = 'Safari'; }
    return "$os navegando en $browser";
}

// --- FUNCIÓN DETECTIVE AUTOMÁTICO ---
function deducirModeloCelular($resolucion, $nucleos, $dispositivo_limpio) {
    $res = trim($resolucion);
    if (strpos(strtolower($dispositivo_limpio), 'iphone') !== false) {
        if ($res == '393x852') return 'iPhone 14 Pro, 15 o 15 Pro';
        if ($res == '390x844') return 'iPhone 12, 13 o 14';
        if ($res == '428x926') return 'iPhone 12/13/14 Pro Max';
        if ($res == '414x896') return 'iPhone 11 o XR';
        if ($res == '375x812') return 'iPhone X, XS o 11 Pro';
        return 'iPhone (Modelo Genérico)';
    } elseif (strpos(strtolower($dispositivo_limpio), 'android') !== false) {
        if ($nucleos == '8' || $nucleos == 8) {
            if ($res == '412x915' || $res == '360x800') return 'Samsung Galaxy Gama Media/Alta (Ej: A53, A54, S22, S23)';
            if ($res == '412x892') return 'Motorola Moderno (Ej: Edge, Moto G recientes)';
            if ($res == '393x873') return 'Xiaomi / Redmi / POCO Moderno';
            return 'Android Gama Media/Alta (Octa-Core)';
        }
        return 'Teléfono Android Genérico';
    } elseif (strpos(strtolower($dispositivo_limpio), 'windows') !== false || strpos(strtolower($dispositivo_limpio), 'mac') !== false) {
        return 'Computadora de Escritorio / Notebook';
    }
    return 'Dispositivo No Identificado';
}

$alerta_seguridad = false;
$ip_visitante = $_SERVER['REMOTE_ADDR'] ?? 'IP Desconocida';
$user_agent_crudo = $_SERVER['HTTP_USER_AGENT'] ?? '';
$fecha_hora = date('d/m/Y H:i:s');

if (!$u) {
    $alerta_seguridad = true;
} else {
    $fecha_str = !empty($u['fecha_creacion']) ? $u['fecha_creacion'] : 'S/D';
    $token_esperado = base64_encode($u['id'] . "-DrUgStOrE-" . $fecha_str);
    if ($token_recibido !== $token_esperado) {
        $alerta_seguridad = true;
    }
}

// --- ACCIÓN: SI DETECTAMOS FRAUDE ---
if ($alerta_seguridad) {
    
    $stmtAdmin = $conexion->query("SELECT email, nombre_completo FROM usuarios ORDER BY id_rol ASC LIMIT 1");
    $adminData = $stmtAdmin ? $stmtAdmin->fetch(PDO::FETCH_ASSOC) : false;
    $correo_destino = ($adminData && !empty($adminData['email'])) ? $adminData['email'] : 'info@federicogonzalez.net';
    $nombre_destino = ($adminData && !empty($adminData['nombre_completo'])) ? $adminData['nombre_completo'] : 'Administrador';

    $dispositivo_limpio = obtenerDispositivoLimpio($user_agent_crudo);
    $modelo_adivinado = deducirModeloCelular($resolucion_obtenida, $nucleos_obtenidos, $dispositivo_limpio);
    
    $gps_html_mail = "";
    if ($lat_obtenida !== 'Desconocida' && $lat_obtenida !== 'Denegado' && $lat_obtenida !== 'No Soportado') {
        $gps_html_mail = "<tr><td><strong>Ubicación Exacta:</strong></td><td><a href='https://www.google.com/maps?q={$lat_obtenida},{$lon_obtenida}' target='_blank' style='color: #d32f2f; font-weight: bold;'>📍 Ver en Google Maps</a></td></tr>";
    } else {
        $gps_html_mail = "<tr><td><strong>Ubicación Exacta:</strong></td><td><em>GPS Denegado por el usuario</em></td></tr>";
    }

    try {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host       = 'smtp.hostinger.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = 'info@federicogonzalez.net'; 
        $mail->Password   = 'Fmg35911@'; 
        $mail->SMTPSecure = 'ssl';
        $mail->Port       = 465;
        $mail->CharSet    = 'UTF-8';
        
        $mail->setFrom('info@federicogonzalez.net', $nombre_negocio . ' - Seguridad');
        $mail->addAddress($correo_destino, $nombre_destino); 
        $mail->isHTML(true);
        $mail->Subject = "🚨 ALERTA CRÍTICA - Intento de fraude con Rastreo";
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 2px solid #d32f2f; border-radius: 10px; overflow: hidden;'>
            <div style='background: #d32f2f; padding: 20px; text-align: center;'>
                <h2 style='color: white; margin: 0;'>⚠️ ALERTA DE INTRUSIÓN</h2>
            </div>
            <div style='padding: 30px; color: #333;'>
                <p>El sistema automático bloqueó un intento de acceso a un código QR manipulado.</p>
                
                <table width='100%' style='background: #ffebee; padding: 15px; border-radius: 5px; margin: 20px 0; border-left: 4px solid #d32f2f;'>
                    <tr><td colspan='2' style='color:#d32f2f; font-weight:bold; padding-bottom:10px;'>DATOS RASTREADOS DEL DISPOSITIVO:</td></tr>
                    <tr><td width='40%'><strong>Hora del Incidente:</strong></td><td>{$fecha_hora}</td></tr>
                    <tr><td><strong>Dispositivo Básico:</strong></td><td>{$dispositivo_limpio}</td></tr>
                    <tr><td><strong>Posible Modelo:</strong></td><td><span style='color: #d32f2f; font-weight: bold; font-size: 1.1em;'>{$modelo_adivinado}</span></td></tr>
                    <tr><td><strong>Dirección IP:</strong></td><td><span style='font-size: 0.85em; color: #666;'>{$ip_visitante}</span></td></tr>
                    {$gps_html_mail}
                    <tr><td colspan='2'><hr style='border-top: 1px solid #ffcc80;'></td></tr>
                    <tr><td><strong>Resolución Cruda:</strong></td><td>{$resolucion_obtenida} píxeles</td></tr>
                    <tr><td><strong>Núcleos Procesador:</strong></td><td>{$nucleos_obtenidos}</td></tr>
                    <tr><td><strong>Idioma y Zona:</strong></td><td>{$idioma_obtenido} ({$zona_obtenida})</td></tr>
                </table>

                <table width='100%' style='background: #f4f7fa; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                    <tr><td colspan='2' style='font-weight:bold; padding-bottom:10px;'>QUÉ INTENTÓ HACER:</td></tr>
                    <tr><td width='40%'><strong>ID Buscado:</strong></td><td>{$id_usuario}</td></tr>
                    <tr><td><strong>Token enviado:</strong></td><td>" . ($token_recibido ?: 'Ninguno (Acceso directo)') . "</td></tr>
                </table>
            </div>
        </div>";
        $mail->send();
    } catch (Exception $e) {}

    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>ACCESO DENEGADO</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    </head>
    <body class="bg-dark text-center" style="display:flex; align-items:center; justify-content:center; min-height:100vh; margin:0; font-family:monospace;">
        <div style="background:#2a0000; color:white; padding:40px 20px; border-radius:15px; border: 2px solid #ff0000; max-width:600px; width:95%; box-shadow: 0 0 50px rgba(255,0,0,0.4);">
            <i class="bi bi-shield-x" style="font-size: 5rem; color: #ff3333;"></i>
            <h1 class="mt-3" style="font-weight:900; letter-spacing:3px; color:#ff3333;">ACCESO RESTRINGIDO</h1>
            <h5 class="text-warning mt-2 mb-4">ALERTA DE SEGURIDAD DETECTADA</h5>
            <p style="font-size:1rem; line-height:1.6;">El código de validación ha sido adulterado. Se prohíbe el acceso a este registro.</p>
            
            <div class="bg-black p-3 text-start mt-4 mb-4 mx-auto" style="border-radius:8px; border:1px solid #ff3333; color:#00ff00; max-width: 500px; font-size: 0.85rem;">
                <div>> DISPOSITIVO IDENTIFICADO: <span class="text-white"><?php echo $dispositivo_limpio; ?></span></div>
                <div>> ENVIANDO REPORTE A LA ADMINISTRACIÓN... [ ENVIADO ]</div>
            </div>
            <p class="text-white-50 small mt-3 mb-0">Abandone esta página de inmediato.</p>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// --- ACCIÓN: SI LA CREDENCIAL ES LEGÍTIMA ---
$fecha_desde = !empty($u['fecha_creacion']) ? strtotime($u['fecha_creacion']) : time();
$fecha_hasta = strtotime('+1 year', $fecha_desde); 
$hoy = time();

if ($u['activo'] == 0) {
    $estado = 'PERSONAL INACTIVO';
    $clase_estado = 'bg-warning text-dark';
    $icono_estado = 'bi-exclamation-triangle-fill';
    $mensaje = 'Autorización revocada.';
} elseif ($hoy > $fecha_hasta) {
    $estado = 'CREDENCIAL VENCIDA';
    $clase_estado = 'bg-danger text-white';
    $icono_estado = 'bi-calendar-x-fill';
    $mensaje = 'El período de validez ha EXPIRADO.';
} else {
    $estado = 'CREDENCIAL VIGENTE';
    $clase_estado = 'bg-success text-white';
    $icono_estado = 'bi-check-circle-fill';
    $mensaje = 'El portador es personal activo autorizado.';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Validación de Identidad - <?php echo $nombre_negocio; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f0f2f5; font-family: 'Arial', sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 15px; }
        .validation-card { max-width: 400px; width: 100%; background: #fff; border-radius: 15px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 1px solid #ddd; }
        .estado-banner { padding: 30px 20px 75px 20px; text-align: center; font-weight: 900; font-size: 1.2rem; letter-spacing: 1px; }
        .avatar-val { width: 120px; height: 120px; border-radius: 50%; object-fit: cover; border: 5px solid #fff; box-shadow: 0 5px 15px rgba(0,0,0,0.15); margin-top: -60px; background: #fff; position: relative; z-index: 10; }
    </style>
</head>
<body>

<div class="validation-card">
    <div class="estado-banner <?php echo $clase_estado; ?>">
        <i class="<?php echo $icono_estado; ?> d-block mb-2" style="font-size: 3rem;"></i>
        <?php echo $estado; ?>
    </div>

    <div class="text-center bg-light pb-4 px-3" style="border-bottom: 1px solid #eee;">
        <?php $foto_val = !empty($u['foto_perfil']) && file_exists('uploads/'.$u['foto_perfil']) ? 'uploads/'.$u['foto_perfil'] : 'img/no-image.png'; ?>
        <img src="<?php echo $foto_val; ?>" class="avatar-val">
        <h4 class="mt-3 mb-0 fw-bold text-dark text-uppercase"><?php echo htmlspecialchars($u['nombre_completo']); ?></h4>
        <div class="badge bg-primary mt-2 px-3 py-2 fw-bold text-uppercase rounded-pill"><?php echo htmlspecialchars($u['nombre_rol']); ?></div>
    </div>

    <div class="p-4">
        <p class="text-center text-muted small fw-bold mb-4"><?php echo $mensaje; ?></p>
        
        <div class="mb-3 border-bottom pb-2">
            <small class="text-muted d-block fw-bold" style="font-size: 0.7rem;">LEGAJO OFICIAL</small>
            <span class="fw-bold text-dark">EMP-<?php echo str_pad($u['id'], 4, '0', STR_PAD_LEFT); ?></span>
        </div>
        <div class="mb-3 border-bottom pb-2">
            <small class="text-muted d-block fw-bold" style="font-size: 0.7rem;">DOCUMENTO DE IDENTIDAD</small>
            <span class="fw-bold text-dark"><?php echo $u['dni'] ?: 'No registrado'; ?></span>
        </div>
    </div>
</div>

</body>
</html>
