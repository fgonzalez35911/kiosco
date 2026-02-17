<?php
/**
 * check_security.php - Blindaje de Sesión y Control de Bloqueo
 */

// 1. CONFIGURACIÓN DE SEGURIDAD DE COOKIES (Antes de session_start)
ini_set('session.cookie_httponly', 1);  // Impide acceso vía JavaScript (Mitiga XSS)
ini_set('session.use_only_cookies', 1); // No permite IDs de sesión en la URL
ini_set('session.cookie_secure', 1);    // Solo envía cookies a través de HTTPS
ini_set('session.cookie_samesite', 'Lax'); // Protege contra ataques CSRF básicos

if (session_status() === PHP_SESSION_NONE) {
    session_start();
    
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
// 2. CONTROL DE ACCESO Y BLOQUEO DE USUARIO
if (isset($_SESSION['usuario_id'])) {
    // Verificar en tiempo real si el usuario fue bloqueado o se forzó su salida
    // Se asume que $conexion ya está disponible por el archivo que incluye a este
    $stmt = $conexion->prepare("SELECT forzar_logout FROM usuarios WHERE id = ?");
    $stmt->execute([$_SESSION['usuario_id']]);
    $bloqueado = $stmt->fetchColumn();

    if ($bloqueado == 1) {
        // Limpiar la sesión y redirigir
        session_unset();
        session_destroy();
        header("Location: index.php?msg=bloqueado");
        exit;
    }
} else {
    // Si no hay sesión iniciada, patear al login
    header("Location: index.php");
    exit;
}
?>