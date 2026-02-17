<?php
// registro_cliente.php - REGISTRO PREMIUM "EL 10" - V8 (FIX MÓVIL Y DISEÑO UNIFICADO)
session_start();
require_once 'includes/db.php';

// 1. OBTENER CONFIGURACIÓN REAL DESDE LA BASE DE DATOS
$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$nombre_negocio = $conf['nombre_negocio'] ?? 'Drogstore El 10';
$direccion = $conf['direccion_local'] ?? 'Av. Siempre Viva 123';
$telefono = $conf['telefono_whatsapp'] ?? '5491166116861';
$logo_db = $conf['logo_url'] ?? 'logo_default.png';

if (isset($_SESSION['cliente_id'])) { header("Location: tienda.php"); exit; }

$error = "";
$exito = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre = trim($_POST['nombre']);
    $dni = trim($_POST['dni']);
    $usuario = trim($_POST['usuario']);
    $email = trim($_POST['email']);
    $pass = $_POST['password'];

    // Verificar si ya existe DNI, Usuario o Email
    $stmtCheck = $conexion->prepare("SELECT id FROM clientes WHERE dni = ? OR usuario = ? OR email = ?");
    $stmtCheck->execute([$dni, $usuario, $email]);
    
    if ($stmtCheck->rowCount() > 0) {
        $error = "Ese DNI, Usuario o Email ya están registrados en el sistema.";
    } else {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $stmt = $conexion->prepare("INSERT INTO clientes (nombre, dni, usuario, email, password, puntos_acumulados, fecha_registro) VALUES (?, ?, ?, ?, ?, 0, NOW())");
        if($stmt->execute([$nombre, $dni, $usuario, $email, $hash])) {
            $_SESSION['cliente_id'] = $conexion->lastInsertId();
            $_SESSION['cliente_nombre'] = $nombre;
            $exito = true;
        } else { 
            $error = "Error crítico al guardar. Verificá que la tabla 'clientes' tenga la columna 'usuario'."; 
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Crear Cuenta | <?php echo htmlspecialchars($nombre_negocio); ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;800&display=swap" rel="stylesheet">
    
    <style>
        :root { --primary-blue: #102A57; --accent-yellow: #ffde00; --soft-violet: #764ba2; }
        
        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(-45deg, #102A57, #764ba2, #21437a);
            background-size: 400% 400%; animation: gradientBG 15s ease infinite;
            min-height: 100vh; display: flex; align-items: center; justify-content: center; margin: 0;
        }
        @keyframes gradientBG { 0% { background-position: 0% 50%; } 50% { background-position: 100% 50%; } 100% { background-position: 0% 50%; } }
        
        .login-card { 
            background: rgba(255, 255, 255, 0.98); border-radius: 30px; 
            box-shadow: 0 25px 50px rgba(0,0,0,0.3); overflow: hidden; 
            width: 100%; max-width: 1100px; display: flex; margin: 20px; 
        }

        /* Lado de Beneficios */
        .sidebar-promo { 
            background: var(--primary-blue); width: 45%; padding: 50px; 
            color: white; display: flex; flex-direction: column; justify-content: center; 
        }
        
        /* Lado del Formulario */
        .form-section { 
            width: 55%; padding: 50px; background: white; 
            min-height: 700px; display: flex; flex-direction: column; 
            justify-content: center; position: relative; 
        }
        
        .logo-container { max-width: 200px; margin: 0 auto 20px; }
        
        .btn-register { 
            background: var(--primary-blue); color: white; border: none; 
            padding: 16px; border-radius: 50px; font-weight: 800; 
            transition: 0.3s; box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .btn-register:hover { background: var(--soft-violet); transform: translateY(-3px); color: white; }

        /* Flecha para móviles */
        .scroll-btn { 
            display: none; color: var(--primary-blue); cursor: pointer; 
            animation: bounce 2s infinite; margin-top: 30px; 
            text-align: center;
        }
        @keyframes bounce { 0%, 20%, 50%, 80%, 100% {transform: translateY(0);} 40% {transform: translateY(-15px);} }

        @media (max-width: 768px) {
            .login-card { flex-direction: column; margin: 10px; }
            .form-section { width: 100%; order: 1; min-height: 95vh; padding: 40px 20px; }
            .sidebar-promo { width: 100%; order: 2; padding: 50px 25px; }
            .scroll-btn { display: block; }
            .logo-container { max-width: 150px; }
        }
    </style>
</head>
<body>

    <div class="login-card animate__animated animate__fadeIn">
        
        <div class="form-section text-center" id="formSide">
            <div class="logo-container">
                <img src="<?php echo htmlspecialchars($logo_db); ?>?v=<?php echo time(); ?>" alt="Logo" class="img-fluid animate__animated animate__zoomIn">
            </div>

            <h4 class="fw-bold mb-4">Hola Crack creá tu cuenta y suma puntos</h4>

            <?php if($error): ?>
                <div class="alert alert-danger animate__animated animate__shakeX py-2 small"><?php echo $error; ?></div>
            <?php endif; ?>

            <?php if($exito): ?>
                <div class="alert alert-success py-4 animate__animated animate__pulse">
                    <h4 class="fw-bold">¡Bienvenido al Club El 10!</h4>
                    <p class="mb-0">Tu cuenta fue creada con éxito. Redirigiendo...</p>
                </div>
                <script>setTimeout(() => { window.location.href='tienda.php'; }, 2000);</script>
            <?php else: ?>
                <form method="POST">
                    <div class="row text-start">
                        <div class="col-12 mb-3">
                            <label class="small fw-bold text-muted ms-2">NOMBRE COMPLETO</label>
                            <input type="text" name="nombre" class="form-control border-2" placeholder="Tu nombre" required style="border-radius:12px;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-muted ms-2">DNI</label>
                            <input type="number" name="dni" class="form-control border-2" placeholder="Tu documento" required style="border-radius:12px;">
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="small fw-bold text-muted ms-2">USUARIO (Alias)</label>
                            <input type="text" name="usuario" class="form-control border-2" placeholder="Tu usuario" required style="border-radius:12px;">
                        </div>
                        <div class="col-12 mb-3">
                            <label class="small fw-bold text-muted ms-2">CORREO ELECTRÓNICO</label>
                            <input type="email" name="email" class="form-control border-2" placeholder="ejemplo@correo.com" required style="border-radius:12px;">
                        </div>
                        <div class="col-12 mb-4">
                            <label class="small fw-bold text-muted ms-2">CREAR CONTRASEÑA</label>
                            <input type="password" name="password" class="form-control border-2" placeholder="••••••••" required style="border-radius:12px;">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-register w-100 py-3">REGISTRARME AHORA <i class="bi bi-person-plus-fill ms-2"></i></button>
                </form>
            <?php endif; ?>

            <div class="mt-4">
                <p class="small">¿Ya sos cliente? <a href="login_cliente.php" class="fw-bold text-decoration-none" style="color:var(--soft-violet)">Iniciá sesión acá</a></p>
            </div>

            <div class="scroll-btn" onclick="document.getElementById('promoSide').scrollIntoView({behavior:'smooth'});">
                <p class="mb-1 small fw-bold text-uppercase">Ver Beneficios</p>
                <i class="bi bi-chevron-double-down fs-2"></i>
            </div>
        </div>

        <div class="sidebar-promo" id="promoSide">
            <div class="animate__animated animate__fadeInRight">
                <h2 class="fw-bold mb-4">🎉 ¡Estás a un paso!</h2>
                <p class="mb-5 opacity-75">Registrate en <b><?php echo htmlspecialchars($nombre_negocio); ?></b> y empezá a sumar puntos hoy mismo.</p>
                
                <div class="d-flex align-items-center mb-4">
                    <i class="bi bi-check-circle-fill fs-3 text-warning me-3"></i>
                    <p class="mb-0">Puntos en cada ticket de compra.</p>
                </div>

                <div class="d-flex align-items-center mb-4">
                    <i class="bi bi-check-circle-fill fs-3 text-warning me-3"></i>
                    <p class="mb-0">Canjes por productos gratis.</p>
                </div>

                <div class="d-flex align-items-center mb-5">
                    <i class="bi bi-check-circle-fill fs-3 text-warning me-3"></i>
                    <p class="mb-0">Ofertas exclusivas para socios.</p>
                </div>
            </div>

            <div class="mt-auto pt-5 border-top border-white border-opacity-25 opacity-75 small">
                <p class="mb-1"><i class="bi bi-geo-alt-fill me-2"></i><?php echo htmlspecialchars($direccion); ?></p>
                <p class="mb-0"><i class="bi bi-whatsapp me-2"></i><?php echo htmlspecialchars($telefono); ?></p>
            </div>
        </div>

    </div>

    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js"></script>
    <script>
        <?php if($exito): ?>
            window.onload = function() {
                confetti({ particleCount: 200, spread: 90, origin: { y: 0.6 }, colors: ['#ffde00', '#ffffff', '#102A57'] });
            };
        <?php endif; ?>
    </script>
</body>
</html>