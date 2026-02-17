<?php
// index.php - ACCESO ADMINISTRATIVO PROFESIONAL "EL 10" - V3 (LOGO CLEAN)
session_start();
require_once 'includes/db.php';

// Si ya está logueado, al dashboard
if (isset($_SESSION['usuario_id'])) {
    header("Location: " . (!empty($_GET['redirect']) ? $_GET['redirect'] : "dashboard.php"));
    exit;
}

// 1. OBTENER CONFIGURACIÓN
$conf = $conexion->query("SELECT nombre_negocio, logo_url FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$nombre_negocio = $conf['nombre_negocio'] ?? 'Kiosco Manager';
$logo_db = $conf['logo_url'] ?? 'img/logo.png';

$error = '';
if (isset($_GET['error'])) {
    if ($_GET['error'] == 'vacios') $error = "⚠️ Completa todos los campos";
    if ($_GET['error'] == 'inactivo') $error = "⛔ Usuario desactivado";
    if ($_GET['error'] == 'incorrecto') $error = "❌ Credenciales no válidas";
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Control de Acceso | <?php echo htmlspecialchars($nombre_negocio); ?></title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css"/>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --deep-blue: #0a1931;
            --primary-blue: #185adb;
            --celeste: #00d2ff;
            --accent-yellow: #ffde00;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(-45deg, #0a1931, #185adb, #102A57, #0a1931);
            background-size: 400% 400%;
            animation: gradientBG 15s ease infinite;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            overflow: hidden;
        }

        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }

        /* Iconos de fondo */
        .bg-floating-icons i {
            position: absolute;
            color: rgba(0, 210, 255, 0.05);
            pointer-events: none;
            z-index: 0;
            animation: floatIcon 12s infinite linear;
        }

        @keyframes floatIcon {
            0% { transform: translateY(110vh) rotate(0deg); opacity: 0; }
            10% { opacity: 1; }
            90% { opacity: 1; }
            100% { transform: translateY(-10vh) rotate(360deg); opacity: 0; }
        }

        .admin-card {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(25px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 40px;
            padding: 3.5rem 3rem;
            box-shadow: 0 40px 100px rgba(0,0,0,0.5);
            width: 100%;
            max-width: 480px;
            color: white;
            z-index: 10;
        }

        /* LOGO: Más grande y sin caja de fondo */
        .logo-img {
            max-height: 140px; /* Tamaño aumentado */
            width: auto;
            filter: drop-shadow(0 10px 20px rgba(0,0,0,0.4));
            margin-bottom: 2rem;
        }

        .form-label {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--celeste);
            margin-left: 10px;
        }

        .input-group-custom {
            background: rgba(255,255,255,0.95);
            border-radius: 18px;
            padding: 5px 15px;
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
            transition: 0.3s;
            border: 2px solid transparent;
        }

        .input-group-custom:focus-within {
            border-color: var(--celeste);
            box-shadow: 0 0 25px rgba(0, 210, 255, 0.2);
        }

        .input-group-custom i { color: var(--deep-blue); font-size: 1.2rem; margin-right: 15px; }

        .input-group-custom input {
            background: transparent;
            border: none;
            padding: 12px 0;
            width: 100%;
            color: var(--deep-blue);
            font-weight: 600;
            outline: none;
        }

        .btn-admin-pro {
            background: linear-gradient(90deg, var(--accent-yellow), #ffc107);
            color: var(--deep-blue);
            font-weight: 800;
            border: none;
            padding: 18px;
            border-radius: 20px;
            width: 100%;
            text-transform: uppercase;
            letter-spacing: 2px;
            transition: 0.4s;
            box-shadow: 0 15px 30px rgba(255, 222, 0, 0.2);
        }

        .btn-admin-pro:hover {
            transform: translateY(-4px);
            background: white;
            color: var(--primary-blue);
            box-shadow: 0 20px 40px rgba(0,0,0,0.4);
        }

        .footer-text { margin-top: 2rem; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; opacity: 0.4; }
    </style>
</head>
<body>

    <div class="bg-floating-icons">
        <i class="bi bi-shield-lock" style="left: 5%; font-size: 4rem; animation-delay: 0s;"></i>
        <i class="bi bi-cpu" style="left: 75%; font-size: 5rem; animation-delay: 1s;"></i>
        <i class="bi bi-safe2" style="left: 40%; font-size: 3.5rem; animation-delay: 8s;"></i>
    </div>

    <div class="admin-card text-center animate__animated animate__fadeIn">
        
        <div class="animate__animated animate__zoomIn">
            <img src="<?php echo htmlspecialchars($logo_db); ?>?v=<?php echo time(); ?>" alt="Logo" class="logo-img">
        </div>

        <h4 class="fw-bold mb-4" style="letter-spacing: -0.5px; opacity: 0.9;">CONTROL GERENCIAL</h4>
        
        <?php if($error): ?>
            <div class="alert alert-danger border-0 animate__animated animate__shakeX py-2 small fw-bold" style="background: rgba(255,0,0,0.2); color: #ffbaba; border-radius: 15px;">
                <?php echo $error; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="auth_login.php">
            <input type="hidden" name="redirect" value="<?php echo htmlspecialchars($_GET['redirect'] ?? ''); ?>">
            
            <div class="text-start">
                <label class="form-label">Usuario de acceso</label>
                <div class="input-group-custom">
                    <i class="bi bi-person-fill"></i>
                    <input type="text" name="usuario" placeholder="Nombre de usuario" required autofocus>
                </div>
            </div>
            
            <div class="text-start">
                <label class="form-label">Contraseña de seguridad</label>
                <div class="input-group-custom">
                    <i class="bi bi-key-fill"></i>
                    <input type="password" name="password" placeholder="••••••••" required>
                </div>
            </div>
            
            <button type="submit" class="btn btn-admin-pro mt-3">
                INGRESAR AL SISTEMA <i class="bi bi-chevron-right ms-1"></i>
            </button>
        </form>
        
        <div class="footer-text">
            Terminal Segura &copy; <?php echo date('Y'); ?> <br>
            <b><?php echo htmlspecialchars($nombre_negocio); ?></b>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.5.1/dist/confetti.browser.min.js"></script>
    <script>
        window.onload = function() {
            confetti({ particleCount: 40, spread: 60, origin: { y: 0.8 }, colors: ['#00d2ff', '#ffffff'] });
        };
    </script>
</body>
</html>