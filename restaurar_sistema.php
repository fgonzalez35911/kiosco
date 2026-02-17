<?php
// restaurar_sistema.php - MÁQUINA DEL TIEMPO V2 (Limpieza Previa)
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 1) {
    header("Location: dashboard.php"); exit;
}

$mensaje = "";
$tipo_mensaje = "";

if (isset($_POST['restaurar_archivo'])) {
    $nombre_archivo = basename($_POST['restaurar_archivo']);
    $ruta_archivo = 'backups/' . $nombre_archivo;
    
    if (file_exists($ruta_archivo)) {
        set_time_limit(600);
        ini_set('memory_limit', '512M');

        try {
            $conexion->query("SET FOREIGN_KEY_CHECKS = 0");
            
            $temp_query = '';
            $lineas = file($ruta_archivo);

            foreach ($lineas as $linea) {
                if (substr($linea, 0, 2) == '--' || trim($linea) == '' || substr($linea, 0, 2) == '/*') {
                    continue;
                }

                $temp_query .= $linea;

                if (substr(trim($linea), -1, 1) == ';') {
                    // LÓGICA QUIRÚRGICA: Si el backup no tiene DROP TABLE, lo forzamos
                    if (stripos($temp_query, 'CREATE TABLE') !== false) {
                        preg_match('/CREATE TABLE `?([a-zA-Z0-9_]+)`?/i', $temp_query, $matches);
                        if (isset($matches[1])) {
                            $tabla_nombre = $matches[1];
                            $conexion->query("DROP TABLE IF EXISTS `$tabla_nombre` text");
                        }
                    }

                    $conexion->query($temp_query);
                    $temp_query = '';
                }
            }

            $conexion->query("SET FOREIGN_KEY_CHECKS = 1");
            
            $check = $conexion->query("SELECT nombre_negocio FROM configuracion WHERE id = 1")->fetch();
            
            if ($check) {
                $mensaje = "¡Sistema Restaurado! '" . htmlspecialchars($check->nombre_negocio) . "' vuelve a estar en cancha.";
                $tipo_mensaje = "success";
            } else {
                $mensaje = "Restauración completada, pero la tabla de configuración está vacía.";
                $tipo_mensaje = "warning";
            }

        } catch (Exception $e) {
            $mensaje = "Error crítico: " . $e->getMessage();
            $tipo_mensaje = "danger";
        }
    }
}

// (El resto del código de listado se mantiene igual...)
$backups = [];
if (is_dir('backups')) {
    $archivos = scandir('backups', SCANDIR_SORT_DESCENDING);
    foreach ($archivos as $arch) {
        if (strpos($arch, '.sql') !== false) {
            $ruta_completa = 'backups/' . $arch;
            $backups[] = [
                'nombre' => $arch,
                'fecha' => date("d/m/Y H:i:s", filemtime($ruta_completa)),
                'peso' => round(filesize($ruta_completa) / 1024, 2) . ' KB'
            ];
        }
    }
}
?>

<?php include 'includes/layout_header.php'; ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="font-cancha mb-0 text-primary"><i class="bi bi-clock-history"></i> Máquina del Tiempo</h2>
            <p class="text-muted mb-0">Restaura el sistema con limpieza automática de tablas.</p>
        </div>
        <a href="reset_sistema.php" class="btn btn-outline-danger fw-bold rounded-pill">
            <i class="bi bi-trash3"></i> Ir a Resetear
        </a>
    </div>

    <?php if($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> fw-bold text-center rounded-4 mb-4 shadow-sm">
            <?php echo $mensaje; ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-header bg-dark text-white fw-bold py-3 px-4">Copias Disponibles</div>
        <div class="card-body p-0">
            <?php if(empty($backups)): ?>
                <div class="text-center py-5 text-muted">No hay backups.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr class="small text-uppercase">
                                <th class="ps-4">Archivo</th>
                                <th>Fecha</th>
                                <th>Peso</th>
                                <th class="text-end pe-4">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($backups as $b): ?>
                            <tr>
                                <td class="ps-4 fw-bold text-primary">
                                    <i class="bi bi-database-fill me-2"></i> <?php echo htmlspecialchars($b['nombre']); ?>
                                </td>
                                <td class="small text-muted"><?php echo $b['fecha']; ?></td>
                                <td><span class="badge bg-secondary"><?php echo $b['peso']; ?></span></td>
                                <td class="text-end pe-4">
                                    <form method="POST" class="d-inline" onsubmit="return confirm('¿RESTAURAR ESTE ESTADO?');">
                                        <input type="hidden" name="restaurar_archivo" value="<?php echo htmlspecialchars($b['nombre']); ?>">
                                        <button type="submit" class="btn btn-primary btn-sm rounded-pill fw-bold px-3">Restaurar</button>
                                    </form>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/layout_footer.php'; ?>
