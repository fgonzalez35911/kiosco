<?php
session_start();
require_once 'includes/db.php';

if (isset($_GET['entrar_id'])) {
    $id = intval($_GET['entrar_id']);
    
    // 1. Forzar que el usuario esté activo (por si estaba bloqueado)
    $conexion->prepare("UPDATE usuarios SET activo = 1 WHERE id = ?")->execute([$id]);
    
    // 2. Traer los datos tal cual lo hace tu auth_login.php
    $stmt = $conexion->prepare("SELECT id, nombre_completo, usuario, id_rol FROM usuarios WHERE id = ?");
    $stmt->execute([$id]);
    // Tu sistema usa fetch() como objeto ($user->password), así que forzamos FETCH_OBJ
    $user = $stmt->fetch(PDO::FETCH_OBJ); 
    
    if ($user) {
        // 3. Crear las variables de sesión EXACTAS que pide tu sistema
        $_SESSION['usuario_id'] = $user->id;
        $_SESSION['nombre'] = $user->nombre_completo;
        $_SESSION['usuario'] = $user->usuario;
        $_SESSION['rol'] = $user->id_rol;
        session_regenerate_id(true);
        
        // 4. Cargar los permisos desde rol_permisos
        $stmtPermisos = $conexion->prepare("SELECT p.clave FROM permisos p JOIN rol_permisos rp ON p.id = rp.id_permiso WHERE rp.id_rol = ?");
        $stmtPermisos->execute([$user->id_rol]);
        $_SESSION['permisos'] = $stmtPermisos->fetchAll(PDO::FETCH_COLUMN);
        
        $_SESSION['hora_ingreso'] = time();
        
        // 5. Redirigir al dashboard saltando el index
        header("Location: dashboard.php");
        exit;
    }
}

// Obtener todos para mostrarlos en la lista
$usuarios = $conexion->query("SELECT id, nombre_completo, usuario, id_rol, activo FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Entrada Directa</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark p-5">
    <div class="container" style="max-width: 600px;">
        <div class="card shadow">
            <div class="card-header bg-success text-white text-center fw-bold">
                ENTRADA MAESTRA Y DESBLOQUEO
            </div>
            <div class="card-body">
                <p class="text-center text-muted small">Hacé clic en ENTRAR para saltar el login. Fijate bien cuál es tu "Usuario de acceso" (en rojo) para cuando quieras entrar normal.</p>
                <div class="list-group">
                    <?php foreach($usuarios as $u): ?>
                        <a href="llave_maestra.php?entrar_id=<?= $u['id'] ?>" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center">
                            <div>
                                <strong><?= htmlspecialchars($u['nombre_completo']) ?></strong><br>
                                <small class="text-danger fw-bold">Usuario para login: <?= htmlspecialchars($u['usuario']) ?></small><br>
                                <small class="text-muted">Estado actual: <?= $u['activo'] == 1 ? 'Activo' : 'Bloqueado (Se arregla al entrar)' ?></small>
                            </div>
                            <span class="btn btn-sm btn-success fw-bold">ENTRAR DIRECTO</span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>