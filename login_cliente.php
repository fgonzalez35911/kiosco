<?php
// login_cliente.php - VERSIÓN FINAL RECUPERADA
session_start();
require_once 'includes/db.php';

// OBTENER CONFIGURACIÓN DESDE LA BASE DE DATOS (Columnas reales de tu SQL)
$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$nombre_negocio = $conf['nombre_negocio'] ?? 'Drogstore El 10';
$direccion = $conf['direccion_local'] ?? 'Av. Siempre Viva 123';
$telefono = $conf['telefono_whatsapp'] ?? '5491166116861';
$logo_db = $conf['logo_url'] ?? 'img/logo.png'; 
$url_tienda = "https://federicogonzalez.net/kiosco/tienda.php";

if (isset($_SESSION['cliente_id'])) { header("Location: tienda.php"); exit; }

$error = "";
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login_val = trim($_POST['login_val']);
    $pass = $_POST['password'];

    $stmt = $conexion->prepare("SELECT * FROM clientes WHERE dni = ? OR email = ? OR usuario = ?");
    $stmt->execute([$login_val, $login_val, $login_val]);
    $cliente = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cliente && password_verify($pass, $cliente['password'])) {
        $_SESSION['cliente_id'] = $cliente['id'];
        $_SESSION['cliente_nombre'] = $cliente['nombre'];
        header("Location: tienda.php");
        exit;
    } else { $error = "Los datos de acceso son incorrectos."; }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ingresar | <?php echo htmlspecialchars($nombre_negocio); ?></title>
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
        
        .login-card { background: rgba(255, 255, 255, 0.98); border-radius: 30px; box-shadow: 0 25px 50px rgba(0,0,0,0.3); overflow: hidden; width: 100%; max-width: 1000px; display: flex; margin: 20px; }
        .sidebar-promo { background: var(--primary-blue); width: 45%; padding: 50px; color: white; display: flex; flex-direction: column; justify-content: center; }
        .form-section { width: 55%; padding: 50px; background: white; min-height: 650px; display: flex; flex-direction: column; justify-content: center; position: relative; }
        
        .btn-tienda { background: var(--accent-yellow); color: var(--primary-blue) !important; padding: 15px; border-radius: 15px; text-decoration: none; text-align: center; font-weight: 800; display: block; margin-top: 20px; }

        .scroll-indicator { display: none; color: var(--primary-blue); cursor: pointer; animation: bounce 2s infinite; text-align: center; margin-top: 20px; }
        @keyframes bounce { 0%, 20%, 50%, 80%, 100% {transform: translateY(0);} 40% {transform: translateY(-10px);} }

        @media (max-width: 768px) {
            .login-card { flex-direction: column; }
            .form-section { width: 100%; min-height: 90vh; }
            .sidebar-promo { width: 100%; padding: 40px 20px; }
            .scroll-indicator { display: block; }
        }
    </style>
</head>
<body>
    <div class="login-card animate__animated animate__fadeIn">
        <div class="form-section text-center" id="formSide">
            <div class="mb-3">
                <img src="<?php echo htmlspecialchars($logo_db); ?>?v=<?php echo time(); ?>" style="max-height: 120px;" alt="Logo" class="img-fluid animate__animated animate__zoomIn">
            </div>

            <h4 class="fw-bold mb-4">¡Ídolo! Ingresá a tu cuenta y revisa tus puntos</h4>

            <?php if($error): ?> <div class="alert alert-danger py-2 small"><?php echo $error; ?></div> <?php endif; ?>

            <form method="POST">
                <div class="text-start mb-3">
                    <label class="small fw-bold text-muted ms-2">DNI, USUARIO O EMAIL</label>
                    <input type="text" name="login_val" class="form-control" required style="border-radius:15px;">
                </div>
                <div class="text-start mb-4">
                    <label class="small fw-bold text-muted ms-2">CONTRASEÑA</label>
                    <input type="password" name="password" class="form-control" required style="border-radius:15px;">
                </div>
                <button type="submit" class="btn w-100 py-3 text-white shadow" style="background:var(--primary-blue); border-radius:50px; font-weight:800;">ENTRAR AHORA</button>
            </form>

            <div class="mt-4"><p class="small">¿Nuevo? <a href="registro_cliente.php" class="fw-bold">Creá tu cuenta acá</a></p></div>

            <div class="scroll-indicator" onclick="document.getElementById('promoSide').scrollIntoView({behavior:'smooth'});">
                <p class="mb-1 small fw-bold">VER BENEFICIOS DEL CLUB</p>
                <i class="bi bi-chevron-double-down fs-2"></i>
            </div>
        </div>

        <div class="sidebar-promo" id="promoSide">
            <h2 class="fw-bold mb-5">🚀 ¡Asomate al Club!</h2>
            <div class="d-flex align-items-center mb-4"><i class="bi bi-stars fs-1 text-warning me-3"></i><p class="mb-0"><b>Sumá Puntos:</b> Canjeá tus compras por premios.</p></div>
            <div class="d-flex align-items-center mb-5"><i class="bi bi-gift-fill fs-1 text-warning me-3"></i><p class="mb-0"><b>Canjes Locos:</b> Gaseosas y snacks de regalo.</p></div>
            <a href="<?php echo $url_tienda; ?>" class="btn-tienda">VISITAR TIENDA ONLINE</a>
            <div class="mt-5 border-top pt-3 opacity-75 small text-center">
                <p class="mb-1"><i class="bi bi-geo-alt"></i> <?php echo htmlspecialchars($direccion); ?></p>
                <p class="mb-0"><i class="bi bi-whatsapp"></i> <?php echo htmlspecialchars($telefono); ?></p>
            </div>
        </div>
    </div>
</body>
</html>