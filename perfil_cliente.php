<?php
// perfil_cliente.php - GESTIÓN COMPLETA DEL PERFIL
session_start();
require_once 'includes/db.php';

// Seguridad: Si no es cliente, al login
if (!isset($_SESSION['cliente_id'])) { header("Location: login_cliente.php"); exit; }

$id_cliente = $_SESSION['cliente_id'];
$mensaje = "";
$clase_mensaje = "";

// 1. LÓGICA DE FOTO DE PERFIL
if (isset($_FILES['foto']) && $_FILES['foto']['error'] === UPLOAD_ERR_OK) {
    $ext = pathinfo($_FILES['foto']['name'], PATHINFO_EXTENSION);
    // Nombre único para evitar caché
    $nombre_archivo = "perfil_" . $id_cliente . "_" . time() . "." . $ext;
    $ruta_destino = "uploads/" . $nombre_archivo;
    
    if (!is_dir('uploads')) mkdir('uploads', 0777, true);
    
    if (move_uploaded_file($_FILES['foto']['tmp_name'], $ruta_destino)) {
        // Guardamos ruta en BD
        $conexion->prepare("UPDATE clientes SET foto_perfil = ? WHERE id = ?")->execute([$ruta_destino, $id_cliente]);
        $mensaje = "Foto actualizada correctamente.";
        $clase_mensaje = "alert-success";
    } else {
        $mensaje = "Error al subir la imagen.";
        $clase_mensaje = "alert-danger";
    }
}

// 2. LÓGICA DE ACTUALIZACIÓN DE DATOS
if (isset($_POST['accion']) && $_POST['accion'] == 'guardar_datos') {
    $direccion = trim($_POST['direccion']);
    $telefono = trim($_POST['telefono']);
    $password_nueva = $_POST['password'];
    
    $sql = "UPDATE clientes SET direccion = ?, telefono = ?";
    $params = [$direccion, $telefono];
    
    // Solo cambiamos contraseña si el usuario escribió algo
    if (!empty($password_nueva)) {
        $sql .= ", password = ?";
        $params[] = password_hash($password_nueva, PASSWORD_DEFAULT);
    }
    
    $sql .= " WHERE id = ?";
    $params[] = $id_cliente;
    
    if($conexion->prepare($sql)->execute($params)) {
        $mensaje = "Tus datos fueron actualizados.";
        $clase_mensaje = "alert-success";
    } else {
        $mensaje = "Error al guardar.";
        $clase_mensaje = "alert-danger";
    }
}

// 3. OBTENER DATOS ACTUALIZADOS DE LA BD
$stmt = $conexion->prepare("SELECT * FROM clientes WHERE id = ?");
$stmt->execute([$id_cliente]);
$cliente = $stmt->fetch(PDO::FETCH_ASSOC);

// Refrescamos variables de sesión para que la tienda se actualice al volver
$_SESSION['cliente_nombre'] = $cliente['nombre'];
$_SESSION['cliente_puntos'] = $cliente['puntos_acumulados'];
$_SESSION['cliente_foto'] = $cliente['foto_perfil'];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Mi Perfil - <?php echo htmlspecialchars($cliente['nombre']); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f0f2f5; font-family: 'Segoe UI', sans-serif; padding-bottom: 50px; }
        
        /* CABECERA CON DEGRADADO */
        .profile-header {
            background: linear-gradient(135deg, #0d6efd 0%, #0099ff 100%);
            padding: 60px 20px 80px;
            color: white;
            border-radius: 0 0 30px 30px;
            margin-bottom: 60px;
            position: relative;
            text-align: center;
            box-shadow: 0 4px 20px rgba(13, 110, 253, 0.3);
        }
        
        /* TARJETA DE PUNTOS FLOTANTE */
        .card-puntos {
            position: absolute;
            bottom: -40px;
            left: 50%;
            transform: translateX(-50%);
            width: 90%;
            max-width: 350px;
            background: white;
            border-radius: 20px;
            padding: 20px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .puntos-valor { font-size: 2.5rem; font-weight: 800; color: #ffc107; line-height: 1; }
        .puntos-label { font-size: 0.8rem; text-transform: uppercase; color: #6c757d; font-weight: 700; }
        
        /* FOTO DE PERFIL */
        .avatar-container {
            position: relative;
            width: 120px; height: 120px;
            margin: 0 auto 15px;
        }
        .avatar-img {
            width: 100%; height: 100%;
            object-fit: cover;
            border-radius: 50%;
            border: 4px solid rgba(255,255,255,0.3);
            background: #fff;
        }
        .btn-camara {
            position: absolute; bottom: 5px; right: 5px;
            background: #212529; color: white;
            width: 35px; height: 35px;
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            cursor: pointer; border: 2px solid white;
        }
        
        .form-card { background: white; border-radius: 15px; padding: 25px; box-shadow: 0 2px 10px rgba(0,0,0,0.05); }
    </style>
</head>
<body>

    <a href="tienda.php" class="btn btn-light rounded-circle shadow position-absolute top-0 start-0 m-3" style="z-index: 100;">
        <i class="bi bi-arrow-left"></i>
    </a>
    
    <a href="logout_cliente.php" class="btn btn-danger rounded-circle shadow position-absolute top-0 end-0 m-3" style="z-index: 100;" onclick="return confirm('¿Cerrar sesión?');">
        <i class="bi bi-box-arrow-right"></i>
    </a>

    <div class="profile-header">
        <form method="POST" enctype="multipart/form-data" id="formFoto">
            <div class="avatar-container">
                <img src="<?php echo !empty($cliente['foto_perfil']) ? $cliente['foto_perfil'] : 'img/default_user.png'; ?>" class="avatar-img">
                <label for="inputFoto" class="btn-camara"><i class="bi bi-camera-fill small"></i></label>
                <input type="file" name="foto" id="inputFoto" hidden accept="image/*" onchange="document.getElementById('formFoto').submit()">
            </div>
        </form>
        
        <h3 class="fw-bold mb-0"><?php echo $cliente['nombre']; ?></h3>
        <p class="opacity-75 small">Miembro del Club</p>

        <div class="card-puntos">
            <div class="text-start">
                <div class="puntos-label">Saldo Disponible</div>
                <div class="small text-muted">Para canjear</div>
            </div>
            <div class="puntos-valor">
                <?php echo $cliente['puntos_acumulados']; ?>
            </div>
        </div>
    </div>

    <div class="container" style="max-width: 600px;">
        
        <?php if($mensaje): ?>
            <div class="alert <?php echo $clase_mensaje; ?> text-center rounded-pill mb-4 shadow-sm border-0">
                <?php echo $mensaje; ?>
            </div>
        <?php endif; ?>
        <a href="cliente_sorteos.php" class="btn btn-warning w-100 mb-4 rounded-pill fw-bold py-3 shadow text-dark text-uppercase">
    <i class="bi bi-ticket-detailed-fill me-2 fs-5"></i> Ver mis Rifas y Sorteos
</a>

        <div class="form-card">
            <h5 class="fw-bold mb-4 text-secondary"><i class="bi bi-person-gear"></i> Mis Datos</h5>
            
            <form method="POST">
                <input type="hidden" name="accion" value="guardar_datos">
                
                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">DNI (Usuario)</label>
                    <input type="text" class="form-control bg-light" value="<?php echo $cliente['dni']; ?>" disabled>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">Dirección de Envío</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-geo-alt text-danger"></i></span>
                        <input type="text" name="direccion" class="form-control border-start-0" value="<?php echo $cliente['direccion']; ?>" placeholder="Ej: Av. Principal 123">
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-bold text-muted">WhatsApp</label>
                    <div class="input-group">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-whatsapp text-success"></i></span>
                        <input type="tel" name="telefono" class="form-control border-start-0" value="<?php echo $cliente['telefono']; ?>">
                    </div>
                </div>

                <div class="mb-4">
                    <label class="form-label small fw-bold text-muted">Cambiar Contraseña</label>
                    <input type="password" name="password" class="form-control" placeholder="Dejar vacío para mantener la actual">
                </div>

                <div class="d-grid">
                    <button type="submit" class="btn btn-primary rounded-pill py-3 fw-bold shadow-sm">
                        GUARDAR CAMBIOS
                    </button>
                </div>
            </form>
        </div>
        
        <div class="text-center mt-4 text-muted small">
            Cliente ID: #<?php echo str_pad($id_cliente, 6, '0', STR_PAD_LEFT); ?>
        </div>
    </div>

</body>
</html>