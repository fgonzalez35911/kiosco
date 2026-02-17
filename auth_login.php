<?php
// auth_login.php - CON CARGA DE PERMISOS Y REDIRECCIÓN QR
session_start();
require_once 'includes/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $usuario = trim($_POST['usuario']);
    $password = trim($_POST['password']);

    if (empty($usuario) || empty($password)) { header("Location: index.php?error=vacios"); exit; }

    $stmt = $conexion->prepare("SELECT id, nombre_completo, password, id_rol, activo, usuario FROM usuarios WHERE usuario = :u LIMIT 1");
    $stmt->execute([':u' => $usuario]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user->password)) {
        if ($user->activo == 0) { header("Location: index.php?error=inactivo"); exit; }

        // DATOS BÁSICOS
        $_SESSION['usuario_id'] = $user->id;
        $_SESSION['nombre'] = $user->nombre_completo;
        $_SESSION['usuario'] = $user->usuario;
        $_SESSION['rol'] = $user->id_rol;
        // SEGURIDAD: Regeneramos el ID de sesión para evitar Session Hijacking
        session_regenerate_id(true);

        // --- CARGAR PERMISOS DEL ROL ---
        $stmtPermisos = $conexion->prepare("
            SELECT p.clave 
            FROM permisos p 
            JOIN rol_permisos rp ON p.id = rp.id_permiso 
            WHERE rp.id_rol = ?
        ");
        $stmtPermisos->execute([$user->id_rol]);
        $_SESSION['permisos'] = $stmtPermisos->fetchAll(PDO::FETCH_COLUMN);
        // --------------------------------------

        // Asistencia
        $_SESSION['hora_ingreso'] = time();
        try {
            $conexion->prepare("INSERT INTO asistencia (id_usuario, ingreso) VALUES (?, NOW())")->execute([$user->id]);
        } catch (Exception $e) {}

        // --- CAMBIO CLAVE: REDIRECCIÓN INTELIGENTE ---
        // Si el Index nos mandó un 'redirect' (ej: reporte_gastos.php), vamos ahí.
        // Si no, vamos al dashboard normal.
        if (!empty($_POST['redirect'])) {
            header("Location: " . $_POST['redirect']);
        } else {
            header("Location: dashboard.php");
        }
        exit;
        // ---------------------------------------------

    } else {
        header("Location: index.php?error=incorrecto");
        exit;
    }
} else {
    header("Location: index.php");
    exit;
}
?>