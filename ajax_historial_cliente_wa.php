<?php
require_once 'includes/db.php';
$email = trim($_GET['email'] ?? '');

if(empty($email)) {
    echo "<p class='text-danger fw-bold'>No hay email registrado para este cliente.</p>";
    exit;
}

$stmt = $conexion->prepare("SELECT estado, COUNT(*) as cantidad FROM pedidos_whatsapp WHERE email_cliente = ? GROUP BY estado");
$stmt->execute([$email]);
$resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totales = ['aprobado' => 0, 'pendiente' => 0, 'entregado' => 0, 'rechazado' => 0, 'no_retirado' => 0];
foreach($resultados as $r) { $totales[strtolower($r['estado'])] = $r['cantidad']; }

$total_general = array_sum($totales);

echo "<div class='text-start'>";
echo "<p class='mb-3 text-muted'><i class='bi bi-envelope-at'></i> <strong>{$email}</strong></p>";
echo "<ul class='list-group shadow-sm mb-3'>";
echo "  <li class='list-group-item d-flex justify-content-between align-items-center bg-light'><strong>Total de Pedidos</strong> <span class='badge bg-dark rounded-pill fs-6'>{$total_general}</span></li>";
echo "  <li class='list-group-item d-flex justify-content-between align-items-center'>✔️ Retirados y Pagados <span class='badge bg-success rounded-pill'>{$totales['entregado']}</span></li>";
echo "  <li class='list-group-item d-flex justify-content-between align-items-center'>⏳ En preparación / Reserva <span class='badge bg-primary rounded-pill'>" . ($totales['aprobado'] + $totales['pendiente']) . "</span></li>";
echo "</ul>";

echo "<h6 class='fw-bold text-danger mt-4 mb-2'>Historial de Cancelaciones</h6>";
echo "<ul class='list-group shadow-sm small'>";
echo "  <li class='list-group-item d-flex justify-content-between align-items-center text-muted'>❌ Rechazados por el local (Sin stock) <span class='badge bg-secondary rounded-pill'>{$totales['rechazado']}</span></li>";
echo "  <li class='list-group-item d-flex justify-content-between align-items-center text-danger'>🚫 No retirados (Culpa del cliente) <span class='badge bg-danger rounded-pill'>{$totales['no_retirado']}</span></li>";
echo "</ul>";

if($totales['no_retirado'] > 0 && $totales['no_retirado'] > $totales['entregado']) {
    echo "<div class='alert alert-danger mt-3 small'><i class='bi bi-exclamation-triangle-fill'></i> <strong>¡ALERTA!</strong> Este cliente reserva y no viene a buscar los productos.</div>";
} else if ($totales['entregado'] > 0) {
    echo "<div class='alert alert-success mt-3 small'><i class='bi bi-star-fill text-warning'></i> Cliente confiable.</div>";
}
echo "</div>";
?>