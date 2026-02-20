<?php
// ajax_stock_reposicion.php
session_start();
header('Content-Type: application/json');

require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) {
    echo json_encode(['status' => 'error', 'msg' => 'Sesión no válida']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id = intval($_POST['id']);
    $cantidad = floatval($_POST['cantidad']);
    $id_proveedor = !empty($_POST['id_proveedor']) ? intval($_POST['id_proveedor']) : null;
    $nuevo_costo = !empty($_POST['nuevo_costo']) ? floatval($_POST['nuevo_costo']) : null;
    $user_id = $_SESSION['usuario_id'];

    if ($id > 0 && $cantidad > 0) {
        try {
            $conexion->beginTransaction();

            $stmtProd = $conexion->prepare("SELECT descripcion, stock_actual, precio_costo FROM productos WHERE id = ?");
            $stmtProd->execute([$id]);
            $prod = $stmtProd->fetch();

            if ($prod) {
                // 1. Actualizar Stock
                $stmtUp = $conexion->prepare("UPDATE productos SET stock_actual = stock_actual + ? WHERE id = ?");
                $stmtUp->execute([$cantidad, $id]);

                // 2. Si se envió un nuevo costo, actualizarlo
                $detalle_costo = "";
                if ($nuevo_costo !== null) {
                    $conexion->prepare("UPDATE productos SET precio_costo = ? WHERE id = ?")->execute([$nuevo_costo, $id]);
                    $detalle_costo = " | Costo actualizado: $" . $prod->precio_costo . " -> $" . $nuevo_costo;
                }

                // 3. Si se seleccionó proveedor, registrarlo en la auditoría
                $nombre_prov = "No especificado";
                if ($id_proveedor) {
                    $stmtP = $conexion->prepare("SELECT empresa FROM proveedores WHERE id = ?");
                    $stmtP->execute([$id_proveedor]);
                    $nombre_prov = $stmtP->fetchColumn();
                    // Opcional: Actualizar el proveedor predeterminado del producto
                    $conexion->prepare("UPDATE productos SET id_proveedor = ? WHERE id = ?")->execute([$id_proveedor, $id]);
                }

                // 4. Registro en Auditoría Profesional
                $detalles = "REPOSICIÓN: +{$cantidad} unidades para '{$prod->descripcion}'. Proveedor: {$nombre_prov}{$detalle_costo}.";
                $stmtAudit = $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'REPOSICION_STOCK', ?, NOW())");
                $stmtAudit->execute([$user_id, $detalles]);

                $conexion->commit();
                echo json_encode(['status' => 'success', 'msg' => 'Ingreso registrado correctamente.']);
            } else {
                throw new Exception("Producto no encontrado.");
            }
        } catch (Exception $e) {
            if ($conexion->inTransaction()) $conexion->rollBack();
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['status' => 'error', 'msg' => 'Datos inválidos']);
    }
}