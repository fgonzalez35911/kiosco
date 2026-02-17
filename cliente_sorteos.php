<?php
session_start();
require_once 'includes/db.php';

// Verificar login de cliente (no admin)
if (!isset($_SESSION['cliente_id'])) { header("Location: login_cliente.php"); exit; }

$idCliente = $_SESSION['cliente_id'];

// Obtener sorteos activos y donde el cliente tiene tickets
$mis_tickets = $conexion->query("
    SELECT st.*, s.titulo, s.estado, s.fecha_sorteo, s.ganadores_json 
    FROM sorteo_tickets st 
    JOIN sorteos s ON st.id_sorteo = s.id 
    WHERE st.id_cliente = $idCliente 
    ORDER BY s.fecha_sorteo DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Agrupar por sorteo
$sorteos_usuario = [];
foreach($mis_tickets as $t) {
    $sorteos_usuario[$t['id_sorteo']]['info'] = $t;
    $sorteos_usuario[$t['id_sorteo']]['numeros'][] = $t['numero_ticket'];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mis Sorteos - Kiosco</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <style>
        body { background-color: #f8f9fa; font-family: 'Poppins', sans-serif; }
        .ticket-badge { font-size: 1.1em; letter-spacing: 2px; border: 2px dashed #ccc; padding: 5px 15px; border-radius: 10px; background: #fff; display: inline-block; margin: 2px; }
        .winner-alert { animation: pulse 2s infinite; }
        @keyframes pulse { 0% { transform: scale(1); } 50% { transform: scale(1.02); } 100% { transform: scale(1); } }
    </style>
</head>
<body>
    <div class="container py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="fw-bold text-primary">Mis Sorteos y Rifas</h2>
            <a href="perfil_cliente.php" class="btn btn-outline-secondary rounded-pill">Volver al Perfil</a>
        </div>

        <?php if(empty($sorteos_usuario)): ?>
            <div class="alert alert-info rounded-4 p-4 text-center">
                <h4><i class="bi bi-ticket-detailed me-2"></i> Aún no tienes tickets.</h4>
                <p>Acércate al mostrador para comprar tu número.</p>
            </div>
        <?php else: ?>
            <div class="row g-4">
                <?php foreach($sorteos_usuario as $idSorteo => $data): 
                    $s = $data['info'];
                    $esGanador = false;
                    $premioGanado = '';
                    
                    // Verificar si ganó
                    if($s['estado'] == 'finalizado' && $s['ganadores_json']) {
                        $ganadores = json_decode($s['ganadores_json'], true);
                        foreach($ganadores as $g) {
                            if(in_array($g['ticket'], $data['numeros'])) {
                                $esGanador = true;
                                $premioGanado = $g['premio'];
                            }
                        }
                    }
                ?>
                <div class="col-md-6">
                    <div class="card h-100 shadow-sm border-0 rounded-4 <?php echo $esGanador ? 'border border-warning winner-alert' : ''; ?>">
                        <div class="card-header bg-white border-0 pt-3">
                            <div class="d-flex justify-content-between">
                                <span class="badge <?php echo $s['estado']=='activo'?'bg-success':'bg-secondary'; ?> rounded-pill"><?php echo strtoupper($s['estado']); ?></span>
                                <small class="text-muted"><?php echo date('d/m/Y', strtotime($s['fecha_sorteo'])); ?></small>
                            </div>
                        </div>
                        <div class="card-body text-center">
                            <h4 class="fw-bold mb-3"><?php echo htmlspecialchars($s['titulo']); ?></h4>
                            
                            <?php if($esGanador): ?>
                                <div class="alert alert-warning fw-bold">
                                    <i class="bi bi-trophy-fill fs-1 d-block mb-2 text-warning"></i>
                                    ¡FELICIDADES! GANASTE:<br>
                                    <span class="text-dark fs-4"><?php echo $premioGanado; ?></span>
                                </div>
                            <?php endif; ?>

                            <p class="text-muted small mb-2">Tus números de la suerte:</p>
                            <div>
                                <?php foreach($data['numeros'] as $n): ?>
                                    <span class="ticket-badge text-primary fw-bold">#<?php echo str_pad($n, 3, '0', STR_PAD_LEFT); ?></span>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>