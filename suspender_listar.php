<?php
// acciones/suspender_listar.php
require_once '../includes/db.php';

$sql = "SELECT id, fecha, nombre_cliente_temporal, total FROM ventas_suspendidas ORDER BY id DESC";

try {
    $ventas = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    if(empty($ventas)) {
        echo '<div class="text-center p-4 text-muted">No hay ventas en espera.</div>';
    } else {
        echo '<div class="list-group">';
        foreach($ventas as $v) {
            $hora = date('H:i', strtotime($v['fecha']));
            $nombre = htmlspecialchars($v['nombre_cliente_temporal']);
            
            echo '
            <div class="list-group-item d-flex justify-content-between align-items-center p-0 border-bottom">
                
                <div class="flex-grow-1 p-3 list-group-item-action" style="cursor:pointer;" onclick="recuperarVentaId('.$v['id'].')">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="badge bg-warning text-dark me-2">'.$hora.'hs</span>
                            <strong class="text-dark">'.$nombre.'</strong>
                        </div>
                        <span class="fw-bold text-success fs-5">$'.number_format($v['total'], 0, ',', '.').'</span>
                    </div>
                </div>

                <button class="btn btn-light text-danger border-start py-3 px-3 rounded-0 h-100" onclick="eliminarVentaSuspendida('.$v['id'].')" title="Eliminar definitivamente">
                    <i class="bi bi-x-lg fw-bold"></i>
                </button>

            </div>';
        }
        echo '</div>';
    }
} catch (Exception $e) {
    echo '<div class="alert alert-danger">Error: '.$e->getMessage().'</div>';
}
?>