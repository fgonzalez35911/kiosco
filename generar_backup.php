<?php
// generar_backup.php - SISTEMA DE RESPALDO PROFESIONAL (Auto-limpiante)
session_start();

if (!isset($_SESSION['usuario_id'])) { 
    die("Acceso denegado."); 
}

$rutas_db = [__DIR__ . '/db.php', __DIR__ . '/includes/db.php', 'db.php', 'includes/db.php'];
$db_encontrada = false;
foreach ($rutas_db as $ruta) { 
    if (file_exists($ruta)) { 
        require_once $ruta; 
        $db_encontrada = true;
        break; 
    } 
}

if (!$db_encontrada) {
    die("Error: No se pudo encontrar el archivo de conexión db.php");
}

try {
    $tables = [];
    $result = $conexion->query("SHOW TABLES");
    while ($row = $result->fetch(PDO::FETCH_NUM)) {
        $tables[] = $row[0];
    }

    $return = "-- EL 10 POS - Backup generado el: " . date('Y-m-d H:i:s') . "\n";
    $return .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        // AGREGAMOS DROP TABLE PARA EVITAR CONFLICTOS AL RESTAURAR
        $return .= "DROP TABLE IF EXISTS `$table`;\n";
        
        $result = $conexion->query("SHOW CREATE TABLE `$table` ");
        $row = $result->fetch(PDO::FETCH_NUM);
        $return .= "\n\n" . $row[1] . ";\n\n";

        $result = $conexion->query("SELECT * FROM `$table` ");
        $num_fields = $result->columnCount();

        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $return .= "INSERT INTO `$table` VALUES(";
            for ($j = 0; $j < $num_fields; $j++) {
                if (isset($row[$j])) {
                    $val = addslashes($row[$j]);
                    $val = str_replace("\n", "\\n", $val);
                    $return .= '"' . $val . '"';
                } else {
                    $return .= 'NULL';
                }
                if ($j < ($num_fields - 1)) {
                    $return .= ',';
                }
            }
            $return .= ");\n";
        }
    }

    $return .= "\nSET FOREIGN_KEY_CHECKS=1;";

    $fecha = date("Y-m-d_H-i-s");
    $nombre_archivo = "backup_el10_" . $fecha . ".sql";

    header('Content-Type: application/octet-stream');
    header("Content-Transfer-Encoding: Binary");
    header("Content-disposition: attachment; filename=\"" . $nombre_archivo . "\"");

    // AUDITORÍA: REGISTRO DE RESPALDO GENERADO
    try {
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'BACKUP', 'Copia de seguridad completa generada y descargada.', NOW())")
                 ->execute([$_SESSION['usuario_id']]);
    } catch (Exception $e) { }
    
    echo $return;
    exit;

} catch (Exception $e) {
    die("Error al generar el backup: " . $e->getMessage());
}
?>
