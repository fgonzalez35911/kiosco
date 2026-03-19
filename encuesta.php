<?php
// encuesta.php - VERSIÓN FINAL DEFINITIVA (BOTÓN COMPARTIR ABAJO + AJUSTE DE ALTURA)
session_start();
error_reporting(0); 

// 1. CONEXIÓN A BASE DE DATOS
$rutas_db = ['db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

// 2. RECUPERAR DATOS EXACTOS
$datos = [
    'nombre' => '',
    'direccion' => '',
    'telefono' => '',
    'logo' => ''
];

try {
    $sql = "SELECT nombre_negocio, direccion_local, telefono_whatsapp, whatsapp_pedidos, logo_url FROM configuracion LIMIT 1"; 
    $stmt = $conexion->query($sql);
    
    if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $datos['nombre']    = $row['nombre_negocio'];
        $datos['direccion'] = $row['direccion_local'];
        $datos['logo']      = $row['logo_url'];
        $datos['telefono']  = !empty($row['whatsapp_pedidos']) ? $row['whatsapp_pedidos'] : $row['telefono_whatsapp'];
    }
} catch (Exception $e) { }

// 3. GENERAR LINK ABSOLUTO PARA WHATSAPP
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$baseUrl = "$protocol://$host$path";

$ogImage = "";
if (!empty($datos['logo'])) {
    if (strpos($datos['logo'], 'http') === 0) {
        $ogImage = $datos['logo'];
    } else {
        $ogImage = $baseUrl . '/' . $datos['logo'];
    }
}

$ogTitle = !empty($datos['nombre']) ? "Encuesta: " . $datos['nombre'] : "Encuesta de Satisfacción";
$shareUrl = "$baseUrl/encuesta.php";

// 4. LÓGICA DE GUARDADO
$mensaje_sweet = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $nivel = $_POST['nivel'] ?? 0;
    $com = trim($_POST['comentario']);
    $nom  = trim($_POST['nombre']);
// Unimos el prefijo con el número y limpiamos cualquier carácter que no sea número
$pref = $_POST['prefijo'] ?? '';
$num  = preg_replace('/[^0-9]/', '', $_POST['contacto']);
$cont = (!empty($num)) ? $pref . $num : '';
    
    if($nivel > 0) {
        try {
            if(empty($nom)) $nom = 'Anónimo';
            $sql = "INSERT INTO encuestas (nivel, comentario, cliente_nombre, contacto, fecha) VALUES (?, ?, ?, ?, NOW())";
            $stmt = $conexion->prepare($sql);
            $stmt->execute([$nivel, $com, $nom, $cont]);
            try {
                $detalles_audit = "Nueva encuesta recibida. Cliente: " . $nom . " | Calificación: " . $nivel . " estrellas";
                $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (1, 'ENCUESTA', ?, NOW())")->execute([$detalles_audit]);
            } catch (Exception $e) {}
            header("Location: encuesta.php?exito=1"); exit;
        } catch (Exception $e) {
            $mensaje_sweet = "Swal.fire('Error', 'Problema al guardar.', 'error');";
        }
    } else {
        $mensaje_sweet = "Swal.fire('Atención', 'Selecciona una carita.', 'warning');";
    }
}

// 5. MODO ADMIN
$esAdmin = isset($_SESSION['usuario_id']);
if ($esAdmin) {
    try {
        $totalEncuestas = $conexion->query("SELECT COUNT(*) FROM encuestas")->fetchColumn();
        $promedio = number_format($conexion->query("SELECT AVG(nivel) FROM encuestas")->fetchColumn(), 1);
        $stmt3 = $conexion->query("SELECT nivel FROM encuestas ORDER BY id DESC LIMIT 1");
        $ultimaNota = $stmt3->fetchColumn() ?: '-';
    } catch (Exception $e) { $totalEncuestas = 0; }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($ogTitle); ?></title>
    
    <meta property="og:title" content="<?php echo htmlspecialchars($ogTitle); ?>" />
    <meta property="og:description" content="¡Tu opinión nos importa! Calificanos en segundos." />
    <meta property="og:image" content="<?php echo $ogImage; ?>" />
    <meta property="og:url" content="<?php echo $shareUrl; ?>" />
    <meta property="og:type" content="website" />

    <?php if(!$esAdmin): ?>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <?php endif; ?>

    <style>
        .main-wrapper { min-height: 100vh; display: flex; justify-content: center; align-items: center; padding: 20px; width: 100%; }
        .card-custom { width: 100%; max-width: 550px; border-radius: 25px; box-shadow: 0 20px 50px rgba(0,0,0,0.3); border: none; background: white; }
        
        /* Emojis */
        .emoji-container { display: flex; justify-content: space-between; padding: 20px 5px; background: #fff; border: 2px dashed #e9ecef; border-radius: 15px; margin-bottom: 25px; }
        .emoji-option { text-align: center; cursor: pointer; transition: 0.2s; flex: 1; }
        .emoji-option label { font-size: 3rem; cursor: pointer; transition: transform 0.2s; display: block; line-height: 1; }
        .emoji-option span { display: block; font-size: 0.75rem; font-weight: bold; margin-top: 10px; color: #6c757d; text-transform: uppercase; letter-spacing: 1px; }
        .emoji-option:hover label { transform: scale(1.2); }
        input[type="radio"] { display: none; }
        input[type="radio"]:checked + label { transform: scale(1.4); }
        
        #e1:checked ~ label { filter: drop-shadow(0 0 10px red); } #e1:checked ~ span { color: #dc3545; }
        #e2:checked ~ label { filter: drop-shadow(0 0 5px orange); } #e2:checked ~ span { color: #fd7e14; }
        #e3:checked ~ label { filter: drop-shadow(0 0 5px grey); } #e3:checked ~ span { color: #6c757d; }
        #e4:checked ~ label { filter: drop-shadow(0 0 10px gold); } #e4:checked ~ span { color: #ffc107; }
        #e5:checked ~ label { filter: drop-shadow(0 0 15px hotpink); } #e5:checked ~ span { color: #d63384; }

        .form-control-lg { border-radius: 10px; font-size: 1rem; padding: 12px; }

        /* Admin Styles */
        .header-blue { background-color: #102A57; color: white; padding: 40px 0; margin-bottom: 30px; border-radius: 0 0 30px 30px; box-shadow: 0 4px 15px rgba(16, 42, 87, 0.25); position: relative; overflow: hidden; }
        .bg-icon-large { position: absolute; top: 50%; right: 20px; transform: translateY(-50%) rotate(-10deg); font-size: 10rem; opacity: 0.1; color: white; pointer-events: none; }
        .stat-card { border: none; border-radius: 15px; padding: 15px 20px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); background: white; height: 100%; display: flex; align-items: center; justify-content: space-between; }
        .icon-box { width: 45px; height: 45px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.4rem; }

        <?php if(!$esAdmin): ?>
            body { background: linear-gradient(135deg, #102A57 0%, #0d6efd 100%); font-family: 'Segoe UI', sans-serif; margin: 0; }
        <?php endif; ?>

        @keyframes strike { 0% { opacity: 0; transform: scale(0.5); } 10% { opacity: 1; transform: scale(1.1); } 15% { transform: scale(1); } 90% { opacity: 1; } 100% { opacity: 0; } }
        .shake { animation: shake 0.5s cubic-bezier(.36,.07,.19,.97) both; }
        @keyframes shake { 10%, 90% { transform: translate3d(-1px, 0, 0); } 20%, 80% { transform: translate3d(2px, 0, 0); } 30%, 50%, 70% { transform: translate3d(-4px, 0, 0); } 40%, 60% { transform: translate3d(4px, 0, 0); } }

        @media (max-width: 576px) { .emoji-option label { font-size: 2rem; } .emoji-option span { font-size: 0.6rem; } }
    </style>
</head>
<body>

    <?php if($esAdmin): ?>
        <?php include 'includes/layout_header.php'; ?>
        <div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden;">

            <i class="bi bi-chat-text-fill bg-icon-large"></i>
            <div class="container position-relative">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <div><h2 class="fw-bold mb-0">Encuestas</h2><p class="opacity-75 mb-0">Panel de Control</p></div>
                </div>
                <div class="row g-3">
                    <div class="col-12 col-md-4"><div class="stat-card"><div><h6 class="text-muted small fw-bold mb-1">Total</h6><h2 class="mb-0 fw-bold"><?php echo $totalEncuestas; ?></h2></div><div class="icon-box bg-primary bg-opacity-10 text-primary"><i class="bi bi-bar-chart-fill"></i></div></div></div>
                    <div class="col-12 col-md-4"><div class="stat-card"><div><h6 class="text-muted small fw-bold mb-1">Promedio</h6><h2 class="mb-0 fw-bold text-warning"><?php echo $promedio; ?></h2></div><div class="icon-box bg-warning bg-opacity-10 text-warning"><i class="bi bi-star-half"></i></div></div></div>
                    <div class="col-12 col-md-4"><div class="stat-card"><div><h6 class="text-muted small fw-bold mb-1">Última</h6><h2 class="mb-0 fw-bold text-success"><?php echo $ultimaNota; ?> / 5</h2></div><div class="icon-box bg-success bg-opacity-10 text-success"><i class="bi bi-check-circle"></i></div></div></div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="main-wrapper">
        <div class="card card-custom">
            <div class="card-header bg-white pt-4 pb-3 border-0 text-center position-relative">
                
                <div class="mb-3">
                    <?php if(!empty($datos['logo']) && file_exists($datos['logo'])): ?>
                        <img src="<?php echo $datos['logo']; ?>" alt="Logo" class="rounded-circle shadow-sm" style="width: 150px; height: 150px; object-fit: cover; border: 4px solid #f8f9fa;">
                    <?php else: ?>
                        <div class="rounded-circle bg-primary bg-opacity-10 d-inline-flex align-items-center justify-content-center text-primary" style="width: 150px; height: 150px;">
                            <i class="bi bi-shop h1 m-0" style="font-size: 4rem;"></i>
                        </div>
                    <?php endif; ?>
                </div>
                
                <h2 class="fw-bold text-dark m-0 mb-3"><?php echo !empty($datos['nombre']) ? strtoupper($datos['nombre']) : 'TU NEGOCIO'; ?></h2>
                
                <div class="d-flex flex-column align-items-center gap-2 mb-2">
                    <?php if(!empty($datos['direccion'])): ?>
                        <a href="https://www.google.com/maps/search/?api=1&query=<?php echo urlencode($datos['direccion']); ?>" target="_blank" class="text-decoration-none badge bg-light text-dark border p-2 px-3 shadow-sm">
                            <i class="bi bi-geo-alt-fill text-danger me-1"></i> <?php echo $datos['direccion']; ?>
                        </a>
                    <?php endif; ?>
                    
                    <?php if(!empty($datos['telefono'])): ?>
                        <?php $waLimpio = preg_replace('/[^0-9]/', '', $datos['telefono']); ?>
                        <a href="https://wa.me/<?php echo $waLimpio; ?>" target="_blank" class="text-decoration-none badge bg-success text-white border border-success p-2 px-3 shadow-sm">
                            <i class="bi bi-whatsapp me-1"></i> <?php echo $datos['telefono']; ?>
                        </a>
                    <?php endif; ?>
                </div>

                <hr class="mx-5 my-4 opacity-25">
                <p class="fw-bold text-primary mb-0 fs-5">¿Cómo te atendimos hoy?</p>
            </div>
            
            <div class="card-body p-4 p-md-5 pt-0">
                <form method="POST">
                    <div class="emoji-container">
                        <div class="emoji-option" onclick="efecto(1)"><input type="radio" name="nivel" value="1" id="e1"><label for="e1">😡</label><span>Mala</span></div>
                        <div class="emoji-option" onclick="efecto(2)"><input type="radio" name="nivel" value="2" id="e2"><label for="e2">☹️</label><span>Regular</span></div>
                        <div class="emoji-option" onclick="efecto(3)"><input type="radio" name="nivel" value="3" id="e3"><label for="e3">😐</label><span>Normal</span></div>
                        <div class="emoji-option" onclick="efecto(4)"><input type="radio" name="nivel" value="4" id="e4"><label for="e4">🙂</label><span>Buena</span></div>
                        <div class="emoji-option" onclick="efecto(5)"><input type="radio" name="nivel" value="5" id="e5"><label for="e5">😍</label><span>Excelente</span></div>
                    </div>

                    <div class="mb-4">
                        <textarea name="comentario" class="form-control form-control-lg bg-light" rows="3" placeholder="¿Querés contarnos algo más?"></textarea>
                    </div>

                    <div class="row g-3 mb-4">
                        <div class="col-6"><div class="input-group"><span class="input-group-text bg-white border-end-0"><i class="bi bi-person"></i></span><input type="text" name="nombre" class="form-control form-control-lg bg-light border-start-0" placeholder="Tu Nombre"></div></div>
                        <div class="col-6">
    <div class="input-group">
        <select name="prefijo" class="input-group-text bg-white border-end-0" style="max-width: 85px; font-size: 0.8rem; cursor: pointer;">
            <option value="549" selected>🇦🇷 +54</option>
            <option value="598">🇺🇾 +598</option>
            <option value="56">🇨🇱 +56</option>
            <option value="591">🇧🇴 +591</option>
            <option value="55">🇧🇷 +55</option>
            <option value="595">🇵🇾 +595</option>
        </select>
        <input type="tel" name="contacto" class="form-control form-control-lg bg-light border-start-0" placeholder="WhatsApp">
    </div>
</div>
                    </div>

                    <div class="d-grid mb-4">
                        <button type="submit" class="btn btn-dark btn-lg py-3 fw-bold shadow-lg" style="border: none;">
                            ENVIAR OPINIÓN <i class="bi bi-send-fill ms-2"></i>
                        </button>
                    </div>

                    <div class="text-center border-top pt-4">
                        <small class="text-muted d-block mb-2">¿Te gusta nuestro servicio?</small>
                        <button type="button" onclick="compartirEncuesta()" class="btn btn-outline-primary rounded-pill px-4 fw-bold shadow-sm">
                            <i class="bi bi-share-fill me-2"></i> COMPARTIR CON AMIGOS
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <?php if($esAdmin): ?>
        <?php include 'includes/layout_footer.php'; ?>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.2/dist/confetti.browser.min.js"></script>

    <script>
        <?php echo $mensaje_sweet; ?>
        if(new URLSearchParams(window.location.search).get('exito')==='1') { Swal.fire({ title: '¡Muchas Gracias!', text: 'Tu opinión nos ayuda a mejorar día a día.', icon: 'success', confirmButtonColor: '#102A57' }).then(() => { window.history.replaceState({}, document.title, window.location.pathname); }); }

        function efecto(nivel) {
            if(nivel == 1) {
                var tiempoDuracion = 3000;
                const contenedor = document.createElement('div');
                // El contenedor ahora usa unidades de viewport dinámicas (dvh) para móviles
                contenedor.style.position = 'fixed'; contenedor.style.top = '0'; contenedor.style.left = '0';
                contenedor.style.width = '100vw'; contenedor.style.height = '100dvh';
                contenedor.style.zIndex = '999999'; contenedor.style.backgroundColor = 'rgba(0, 0, 0, 0.85)';
                contenedor.style.display = 'flex'; contenedor.style.justifyContent = 'center'; contenedor.style.alignItems = 'center';
                contenedor.style.pointerEvents = 'none';

                const img = document.createElement('img');
                img.src = 'img/norris.gif'; 
                // Aplicamos la animación SOLO a la imagen para no mover el centro del contenedor
                img.style.animation = `strike ${tiempoDuracion}ms ease-out forwards`;
                img.style.maxWidth = '85vw'; 
                img.style.maxHeight = '70dvh'; 
                img.style.width = 'auto'; img.style.height = 'auto';
                img.style.objectFit = 'contain';
                img.style.border = '5px solid #000000'; img.style.boxShadow = '0 0 50px #000000'; img.style.borderRadius = '15px';

                contenedor.appendChild(img);
                document.body.appendChild(contenedor);
                
                // Sacudimos solo el contenido, así el GIF se mantiene centrado aunque haya scroll
                const contenido = document.querySelector('.main-wrapper');
                if(contenido) contenido.classList.add('shake');
                
                setTimeout(() => { 
                    contenedor.remove(); 
                    if(contenido) contenido.classList.remove('shake'); 
                }, tiempoDuracion); 
            }
            // Restauramos los efectos de las otras caritas que faltaban
            if(nivel == 2) { var end = Date.now() + 1000; (function frame() { confetti({ particleCount: 5, angle: 90, spread: 90, origin: { x: Math.random(), y: -0.1 }, colors: ['#87CEEB', '#4682B4', '#00008B'], shapes: ['circle'], gravity: 4, startVelocity: 40, scalar: 0.8, ticks: 300 }); if (Date.now() < end) requestAnimationFrame(frame); }()); }
            if(nivel == 3) { confetti({ particleCount: 100, spread: 70, origin: { y: 0.6 }, colors: ['#0d6efd', '#0dcaf0'] }); }
            if(nivel == 4) { confetti({ particleCount: 60, spread: 80, origin: { y: 0.6 }, shapes: ['star'], colors: ['#FFD700', '#FFA500'] }); }
            if(nivel == 5) { var duration = 2000; var animationEnd = Date.now() + duration; var defaults = { startVelocity: 30, spread: 360, ticks: 60, zIndex: 0 }; var interval = setInterval(function() { var timeLeft = animationEnd - Date.now(); if (timeLeft <= 0) return clearInterval(interval); var particleCount = 50 * (timeLeft / duration); confetti({ ...defaults, particleCount, origin: { x: Math.random(), y: Math.random() - 0.2 }, shapes: ['heart'], colors: ['#FF0000', '#D63384', '#FF69B4'], scalar: 2 }); }, 250); }
        }

        function compartirEncuesta() {
            var nombreNegocio = "<?php echo htmlspecialchars($datos['nombre']); ?>";
            const url = window.location.href; 
            if (navigator.share) { navigator.share({ title: 'Encuesta: ' + nombreNegocio, text: '¡Danos tu opinión sobre ' + nombreNegocio + '!', url: url }); } 
            else { navigator.clipboard.writeText(url).then(() => { Swal.fire({ icon: 'success', title: 'Link Copiado', text: 'Compartilo en WhatsApp', timer: 1500, showConfirmButton: false }); }); }
        }
    </script>
</body>
</html>