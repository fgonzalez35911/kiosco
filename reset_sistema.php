<?php
// reset_sistema.php - CON BACKUP DE SEGURIDAD AUTOMÁTICO
session_start();
require_once 'includes/db.php';

// SEGURIDAD: Solo Admin (Rol 1)
if (!isset($_SESSION['usuario_id']) || $_SESSION['rol'] != 1) {
    header("Location: dashboard.php"); exit;
}

$mensaje = "";
$tipo_mensaje = "";

// FUNCIÓN PARA GENERAR BACKUP SQL
function crearBackup($conexion) {
    $tablas = [];
    $stmt = $conexion->query("SHOW TABLES");
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) { $tablas[] = $row[0]; }

    $sqlScript = "-- BACKUP AUTOMATICO PRE-RESETEO\n";
    $sqlScript .= "-- FECHA: " . date('Y-m-d H:i:s') . "\n\n";
    $sqlScript .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tablas as $tabla) {
        // Estructura
        $row = $conexion->query("SHOW CREATE TABLE $tabla")->fetch(PDO::FETCH_NUM);
        $sqlScript .= "DROP TABLE IF EXISTS `$tabla`;\n";
        $sqlScript .= $row[1] . ";\n\n";

        // Datos
        $stmt = $conexion->query("SELECT * FROM $tabla");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $sqlScript .= "INSERT INTO `$tabla` VALUES(";
            $valores = [];
            foreach ($row as $val) {
                $val = addslashes($val);
                $val = str_replace("\n", "\\n", $val);
                $valores[] = '"' . $val . '"';
            }
            $sqlScript .= implode(',', $valores);
            $sqlScript .= ");\n";
        }
        $sqlScript .= "\n";
    }
    $sqlScript .= "SET FOREIGN_KEY_CHECKS=1;";

    // Guardar archivo
    if (!is_dir('backups')) mkdir('backups', 0777, true);
    $nombreArchivo = 'backups/respaldo_pre_reset_' . date('Y-m-d_H-i-s') . '.sql';
    file_put_contents($nombreArchivo, $sqlScript);
    return $nombreArchivo;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $password = $_POST['password'];
    $uid = $_SESSION['usuario_id'];

    // Verificar contraseña
    $stmt = $conexion->prepare("SELECT password FROM usuarios WHERE id = ?");
    $stmt->execute([$uid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        try {
            // PASO 1: CREAR BACKUP DE SEGURIDAD
            $archivoBackup = crearBackup($conexion);
            
            // PASO 2: EJECUTAR LIMPIEZA
            $conexion->query("SET FOREIGN_KEY_CHECKS = 0");

            if (isset($_POST['check_ventas'])) {
                $conexion->query("TRUNCATE TABLE detalle_ventas");
                $conexion->query("TRUNCATE TABLE pagos_ventas");
                $conexion->query("TRUNCATE TABLE devoluciones");
                $conexion->query("TRUNCATE TABLE ventas");
                $conexion->query("TRUNCATE TABLE cajas_sesion");
                $fecha_hoy = date('Y-m-d H:i:s');
                $conexion->query("INSERT INTO cajas_sesion (id, id_usuario, monto_inicial, total_ventas, estado, fecha_apertura, fecha_cierre) VALUES (1, 1, 0, 0, 'cerrada', '$fecha_hoy', '$fecha_hoy')");
            }

            if (isset($_POST['check_mermas'])) { $conexion->query("TRUNCATE TABLE mermas"); }
            if (isset($_POST['check_gastos'])) { $conexion->query("TRUNCATE TABLE gastos"); }
            
            if (isset($_POST['check_sorteos'])) {
                $conexion->query("TRUNCATE TABLE sorteo_tickets");
                $conexion->query("TRUNCATE TABLE sorteo_premios");
                $conexion->query("TRUNCATE TABLE sorteos");
            }

            if (isset($_POST['check_productos'])) {
                $conexion->query("TRUNCATE TABLE mermas");
                $conexion->query("TRUNCATE TABLE combo_items");
                $conexion->query("TRUNCATE TABLE productos");
            }

            if (isset($_POST['check_clientes'])) {
                $conexion->query("DELETE FROM movimientos_cc");
                $conexion->query("DELETE FROM encuestas");
                $conexion->query("DELETE FROM clientes WHERE id > 1");
                $conexion->query("ALTER TABLE clientes AUTO_INCREMENT = 2");
            }
            if (isset($_POST['check_auditoria'])) { 
                $conexion->query("TRUNCATE TABLE auditoria"); 
                try { $conexion->query("TRUNCATE TABLE historial_inflacion"); } catch(Exception $e) {}
            }
            if (isset($_POST['check_proveedores'])) {
                try { $conexion->query("TRUNCATE TABLE movimientos_proveedores"); } catch(Exception $e) {}
            }
            if (isset($_POST['check_activos'])) {
                try { $conexion->query("TRUNCATE TABLE activos"); } catch(Exception $e) {}
                try { $conexion->query("TRUNCATE TABLE bienes"); } catch(Exception $e) {}
            }
            if (isset($_POST['check_auditoria'])) { $conexion->query("TRUNCATE TABLE auditoria"); }

            $conexion->query("SET FOREIGN_KEY_CHECKS = 1");
            
            $mensaje = "<strong>¡ÉXITO!</strong> Sistema reseteado.<br>Se creó un respaldo de seguridad en: <code>$archivoBackup</code>";
            $tipo_mensaje = "success";

        } catch (Exception $e) {
            $mensaje = "Error crítico: " . $e->getMessage();
            $tipo_mensaje = "danger";
        }
    } else {
        $mensaje = "Contraseña incorrecta.";
        $tipo_mensaje = "danger";
    }
}
?>

<?php include 'includes/layout_header.php'; ?>

<div class="container py-5">
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card shadow-lg border-0 rounded-4">
                <div class="card-header bg-danger text-white text-center py-4 rounded-top-4">
                    <h2 class="fw-bold mb-0"><i class="bi bi-exclamation-triangle-fill"></i> ZONA DE PELIGRO</h2>
                    <p class="mb-0 opacity-75">Reseteo selectivo para pruebas piloto</p>
                </div>
                <div class="card-body p-5">
                    
                    <?php if($mensaje): ?>
                        <div class="alert alert-<?php echo $tipo_mensaje; ?> text-center mb-4 rounded-4 shadow-sm border-0">
                            <?php echo $mensaje; ?>
                            <?php if($tipo_mensaje == 'success'): ?>
                                <div class="mt-2">
                                    <a href="restaurar_sistema.php" class="btn btn-sm btn-dark fw-bold"><i class="bi bi-arrow-counterclockwise"></i> Ir a Restaurar Backup</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="alert alert-info border-info d-flex align-items-center rounded-4" role="alert">
                        <i class="bi bi-shield-lock-fill fs-2 me-3 text-primary"></i>
                        <div>
                            <strong>Tranquilo:</strong> Antes de borrar nada, el sistema hará un <u>Backup Automático</u> de todo. Si te equivocas, podrás restaurarlo desde el menú "Restaurar".
                        </div>
                    </div>

                    <form method="POST" onsubmit="return confirm('¿CONFIRMAR ELIMINACIÓN?');">
                        <h5 class="fw-bold text-secondary mb-3">¿Qué quieres borrar?</h5>
                        
                        <div class="list-group mb-4 shadow-sm">
                            <label class="list-group-item d-flex gap-3">
                                <input class="form-check-input flex-shrink-0" type="checkbox" name="check_ventas" checked style="font-size: 1.3em;">
                                <span><strong>Ventas y Cajas</strong><small class="d-block text-muted">Historial de dinero y tickets.</small></span>
                            </label>
                            <label class="list-group-item d-flex gap-3">
                                <input class="form-check-input flex-shrink-0" type="checkbox" name="check_mermas" checked style="font-size: 1.3em;">
                                <span><strong>Mermas</strong><small class="d-block text-muted">Mercadería perdida/rota.</small></span>
                            </label>
                            <label class="list-group-item d-flex gap-3">
                                <input class="form-check-input flex-shrink-0" type="checkbox" name="check_gastos" checked style="font-size: 1.3em;">
                                <span><strong>Gastos</strong><small class="d-block text-muted">Elimina gastos.</small></span>
                            </label>
                            <label class="list-group-item d-flex gap-3">
                                <input class="form-check-input flex-shrink-0" type="checkbox" name="check_sorteos" checked style="font-size: 1.3em;">
                                <span><strong>Sorteos</strong><small class="d-block text-muted">Sorteos de prueba y tickets.</small></span>
                            </label>
                            <label class="list-group-item d-flex gap-3 bg-light">
                                <input class="form-check-input flex-shrink-0" type="checkbox" name="check_auditoria" checked style="font-size: 1.3em;">
                                <span><strong>Auditoría e Inflación</strong><small class="d-block text-muted">Logs de seguridad y aumentos de precios.</small></span>
                            </label>
                            <label class="list-group-item d-flex gap-3 bg-light border-warning">
                                <input class="form-check-input flex-shrink-0" type="checkbox" name="check_proveedores" checked style="font-size: 1.3em;">
                                <span><strong>Cuentas Proveedores</strong><small class="d-block text-muted">Elimina saldos, facturas y pagos (Quedan los nombres).</small></span>
                            </label>
                            <label class="list-group-item d-flex gap-3 bg-light border-warning">
                                <input class="form-check-input flex-shrink-0" type="checkbox" name="check_activos" checked style="font-size: 1.3em;">
                                <span><strong>Activos y Bienes</strong><small class="d-block text-muted">Vacía el inventario de bienes del local.</small></span>
                            </label>
                            <label class="list-group-item d-flex gap-3 bg-light border-danger">
                                <input class="form-check-input flex-shrink-0" type="checkbox" name="check_productos" style="font-size: 1.3em;">
                                <span><strong class="text-danger">Productos (¡CUIDADO!)</strong><small class="d-block text-danger">Borra TODO el catálogo.</small></span>
                            </label>
                            <label class="list-group-item d-flex gap-3 bg-light border-danger">
                                <input class="form-check-input flex-shrink-0" type="checkbox" name="check_clientes" style="font-size: 1.3em;">
                                <span><strong class="text-danger">Clientes (¡CUIDADO!)</strong><small class="d-block text-danger">Borra la base de clientes.</small></span>
                            </label>
                        </div>

                        <div class="mb-4">
                            <input type="password" name="password" class="form-control form-control-lg text-center rounded-pill" placeholder="Contraseña de Admin para confirmar" required>
                        </div>

                        <button type="submit" class="btn btn-danger w-100 py-3 fw-bold rounded-pill shadow">
                            <i class="bi bi-trash3-fill me-2"></i> EJECUTAR LIMPIEZA CON BACKUP
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/layout_footer.php'; ?>