<?php
// apertura_caja.php - INICIAR TURNO / DÍA
session_start();
require_once 'includes/db.php';

// Si ya hay una caja abierta, mandar a ventas directo
$usuario_id = $_SESSION['usuario_id'];
$stmt = $conexion->prepare("SELECT id FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta'");
$stmt->execute([$usuario_id]);
if($stmt->fetch()) { header("Location: ventas.php"); exit; }

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $monto_inicial = $_POST['monto_inicial'];
    $fecha = date('Y-m-d H:i:s');
    
    // Crear nueva sesión
    $sql = "INSERT INTO cajas_sesion (id_usuario, fecha_apertura, monto_inicial, estado) VALUES (?, ?, ?, 'abierta')";
    $stmt = $conexion->prepare($sql);
    $stmt->execute([$usuario_id, $fecha, $monto_inicial]);
    $id_caja_nueva = $conexion->lastInsertId();

    // AUDITORÍA: APERTURA DE CAJA
    try {
        $detalles_audit = "Apertura de caja #" . $id_caja_nueva . " con monto inicial: $" . number_format($monto_inicial, 2);
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'APERTURA', ?, NOW())")
                 ->execute([$usuario_id, $detalles_audit]);
    } catch (Exception $e) { }
    
    header("Location: ventas.php"); exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Abrir Caja</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-dark d-flex justify-content-center align-items-center" style="height: 100vh;">
    <div class="card shadow p-4" style="width: 400px;">
        <h3 class="text-center mb-4">☀️ Iniciar Día / Turno</h3>
        <form method="POST">
            <div class="mb-3">
                <label class="form-label fw-bold">Dinero en Caja (Cambio Inicial)</label>
                <div class="input-group input-group-lg">
                    <span class="input-group-text">$</span>
                    <input type="number" step="0.01" name="monto_inicial" class="form-control fw-bold" required placeholder="0.00" autofocus>
                </div>
                <div class="form-text">Contá la plata que dejás para cambio.</div>
            </div>
            <button type="submit" class="btn btn-primary w-100 btn-lg fw-bold">ABRIR CAJA</button>
        </form>
    </div>
</body>
</html>