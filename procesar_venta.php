<?php
// procesar_venta.php - VERSIÓN BLINDADA (Corregido error fatal de fetch object/array)
// Se desactiva el reporte de errores en pantalla para no romper el JSON con Warnings
error_reporting(0); 
ini_set('display_errors', 0);

session_start();
require_once '../includes/db.php';
require_once '../includes/interfaz_helper.php';
header('Content-Type: application/json');

try {
    // 1. VERIFICACIÓN DE SESIÓN
    if (!isset($_SESSION['usuario_id'])) { 
        throw new Exception('La sesión ha expirado. Inicia sesión nuevamente.'); 
    }

    // 2. RECEPCIÓN DE DATOS
    $items = $_POST['items'] ?? [];
    // SEGURIDAD: Forzamos tipos de datos para evitar inyecciones
$id_cliente = (isset($_POST['id_cliente']) && $_POST['id_cliente'] !== '') ? intval($_POST['id_cliente']) : null;
$metodo_pago = preg_replace('/[^a-zA-Z0-9_]/', '', $_POST['metodo_pago'] ?? 'efectivo');
$total = floatval($_POST['total'] ?? 0);
    $user_id = $_SESSION['usuario_id'];

    // Datos extra blindados
    $cupon_codigo = isset($_POST['cupon_codigo']) ? preg_replace('/[^a-zA-Z0-9]/', '', $_POST['cupon_codigo']) : null;
    $desc_cupon_monto = floatval($_POST['desc_cupon_monto'] ?? 0);
    $desc_manual_monto = floatval($_POST['desc_manual_monto'] ?? 0);
    $saldo_favor_usado = floatval($_POST['saldo_favor_usado'] ?? 0);
    $pago_deuda = floatval($_POST['pago_deuda'] ?? 0);

    if(empty($items)) { 
        throw new Exception('El carrito está vacío.'); 
    }

    $conexion->beginTransaction();

    // ---------------------------------------------------------
    // 3. VERIFICAR CAJA ABIERTA
    // ---------------------------------------------------------
    $stmtCaja = $conexion->prepare("SELECT id FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta'");
    $stmtCaja->execute([$user_id]);
    $caja = $stmtCaja->fetch(PDO::FETCH_ASSOC);
    
    if(!$caja) { 
        throw new Exception("No tienes una caja abierta. Por favor realiza la apertura de caja."); 
    }
    $id_caja_sesion = $caja['id'];
    $conf = $conexion->query("SELECT dinero_por_punto, redondeo_auto FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    $redondeo_activo = (isset($conf['redondeo_auto']) && $conf['redondeo_auto'] == 1);

    // ---------------------------------------------------------
    // 4. VALIDACIÓN DE STOCK (PREVIA A LA VENTA)
    // ---------------------------------------------------------
    foreach($items as $item) {
        // Consultamos datos seguros con FETCH_ASSOC
        $stmtProd = $conexion->prepare("SELECT descripcion, stock_actual, tipo, codigo_barras FROM productos WHERE id = ?");
        $stmtProd->execute([$item['id']]);
        $prodDB = $stmtProd->fetch(PDO::FETCH_ASSOC);

        if (!$prodDB) throw new Exception("El producto ID {$item['id']} ya no existe en el sistema.");

        $cantidad_venta = $item['cantidad'];

        // LÓGICA DE COMBOS
        if ($prodDB['tipo'] === 'combo') {
            // --- NUEVA VALIDACIÓN DE VIGENCIA ---
            $hoy = date('Y-m-d');
            // Buscamos la regla del combo usando el código de barras
            $stmtVigencia = $conexion->prepare("SELECT fecha_inicio, fecha_fin, es_ilimitado, nombre FROM combos WHERE codigo_barras = ? LIMIT 1");
            $stmtVigencia->execute([$prodDB['codigo_barras']]);
            $reglaCombo = $stmtVigencia->fetch(PDO::FETCH_ASSOC);

            if ($reglaCombo) {
                if ($reglaCombo['es_ilimitado'] == 0) {
                    if ($hoy < $reglaCombo['fecha_inicio'] || $hoy > $reglaCombo['fecha_fin']) {
                        throw new Exception("La oferta '{$reglaCombo['nombre']}' ya no está vigente o venció.");
                    }
                }
            }
            // ------------------------------------
            $id_combo_real = null;
            
            // A. Buscar por código de barras
            if (!empty($prodDB['codigo_barras'])) { 
                 $stmtLink = $conexion->prepare("SELECT id FROM combos WHERE codigo_barras = ? LIMIT 1");
                 $stmtLink->execute([$prodDB['codigo_barras']]); 
                 $resLink = $stmtLink->fetch(PDO::FETCH_ASSOC);
                 if($resLink) $id_combo_real = $resLink['id'];
            }

            // B. Buscar por nombre exacto (Fallback)
            if (!$id_combo_real) {
                $stmtLink = $conexion->prepare("SELECT id FROM combos WHERE nombre = ? LIMIT 1");
                $stmtLink->execute([$prodDB['descripcion']]);
                $resLink = $stmtLink->fetch(PDO::FETCH_ASSOC);
                if($resLink) $id_combo_real = $resLink['id'];
            }

            // Validar Stock Hijos
            if ($id_combo_real) {
                $stmtHijos = $conexion->prepare("
                    SELECT p.descripcion, p.stock_actual, ci.cantidad as cant_necesaria 
                    FROM combo_items ci 
                    JOIN productos p ON ci.id_producto = p.id 
                    WHERE ci.id_combo = ?
                ");
                $stmtHijos->execute([$id_combo_real]);
                $hijos = $stmtHijos->fetchAll(PDO::FETCH_ASSOC);

                if (!empty($hijos)) {
                    foreach ($hijos as $hijo) {
                        $total_necesario = $hijo['cant_necesaria'] * $cantidad_venta;
                        // Forzamos floatval para evitar errores de comparación numérica
                        if (floatval($hijo['stock_actual']) < $total_necesario) {
                            throw new Exception("Falta mercadería para el combo: '{$hijo['descripcion']}'. (Stock: ".floatval($hijo['stock_actual']).", Necesitas: $total_necesario)");
                        }
                    }
                } else {
                    // Combo vacío: Validar stock padre
                    if (floatval($prodDB['stock_actual']) < $cantidad_venta) {
                        throw new Exception("Stock insuficiente para el combo '{$prodDB['descripcion']}'.");
                    }
                }
            } else {
                 // No se encontró enlace: Validar stock padre
                 if (floatval($prodDB['stock_actual']) < $cantidad_venta) {
                    throw new Exception("Stock insuficiente para '{$prodDB['descripcion']}' (Link Combo no encontrado).");
                }
            }
        } 
        // LÓGICA PRODUCTO SIMPLE
        elseif ($prodDB['tipo'] !== 'pesable') { 
            if (floatval($prodDB['stock_actual']) < $cantidad_venta) {
                throw new Exception("Stock insuficiente para '{$prodDB['descripcion']}'. (Stock: ".floatval($prodDB['stock_actual']).")");
            }
        }
    }

    // ---------------------------------------------------------
    // 5. REGISTRO DE LA VENTA (CABECERA)
    // ---------------------------------------------------------
    $total = redondearVenta($total, $redondeo_activo);
    $fecha_actual = date('Y-m-d H:i:s');
    
    $sql = "INSERT INTO ventas (id_caja_sesion, id_usuario, id_cliente, total, metodo_pago, fecha, estado, descuento_monto_cupon, descuento_manual, codigo_cupon) 
            VALUES (?, ?, ?, ?, ?, ?, 'completada', ?, ?, ?)";
    $stmt = $conexion->prepare($sql);
    $stmt->execute([$id_caja_sesion, $user_id, $id_cliente, $total, $metodo_pago, $fecha_actual, $desc_cupon_monto, $desc_manual_monto, $cupon_codigo]);
    
    $venta_id = $conexion->lastInsertId();

    // ---------------------------------------------------------
    // 6. DETALLES Y DESCUENTO DE STOCK REAL
    // ---------------------------------------------------------
    $sqlDetalle = "INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, precio_historico, subtotal) VALUES (?, ?, ?, ?, ?)";
    $stmtDetalle = $conexion->prepare($sqlDetalle);

    foreach($items as $item) {
        $subtotal = $item['precio'] * $item['cantidad'];
        $stmtDetalle->execute([$venta_id, $item['id'], $item['cantidad'], $item['precio'], $subtotal]);

        // Verificamos tipo nuevamente para estar seguros
        $stmtTipo = $conexion->prepare("SELECT tipo, codigo_barras, descripcion FROM productos WHERE id = ?");
        $stmtTipo->execute([$item['id']]);
        $dProd = $stmtTipo->fetch(PDO::FETCH_ASSOC); // AQUÍ ESTABA EL ERROR: Faltaba FETCH_ASSOC
        
        if ($dProd && $dProd['tipo'] === 'combo') {
            $id_combo_real = null;
            
            // Buscar ID Combo (Misma lógica segura)
            if(!empty($dProd['codigo_barras'])) {
                $stmtLink = $conexion->prepare("SELECT id FROM combos WHERE codigo_barras = ? LIMIT 1");
                $stmtLink->execute([$dProd['codigo_barras']]);
                $res = $stmtLink->fetch(PDO::FETCH_ASSOC); // AQUÍ ESTABA EL ERROR: Faltaba FETCH_ASSOC
                if($res) $id_combo_real = $res['id'];
            }
            if(!$id_combo_real) {
                $stmtLink = $conexion->prepare("SELECT id FROM combos WHERE nombre = ? LIMIT 1");
                $stmtLink->execute([$dProd['descripcion']]);
                $res = $stmtLink->fetch(PDO::FETCH_ASSOC); // AQUÍ ESTABA EL ERROR: Faltaba FETCH_ASSOC
                if($res) $id_combo_real = $res['id'];
            }

            // Descontar
            $descontado_ingredientes = false;
            if ($id_combo_real) {
                $stmtHijos = $conexion->prepare("SELECT id_producto, cantidad FROM combo_items WHERE id_combo = ?");
                $stmtHijos->execute([$id_combo_real]);
                $hijos = $stmtHijos->fetchAll(PDO::FETCH_ASSOC);
                
                if (!empty($hijos)) {
                    foreach ($hijos as $hijo) {
                        $descuento_real = $hijo['cantidad'] * $item['cantidad'];
                        $conexion->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?")->execute([$descuento_real, $hijo['id_producto']]);
                    }
                    $descontado_ingredientes = true;
                }
            }
            
            // Si es un combo, JAMÁS tocamos el stock del producto padre (id).
            // Solo descontamos los hijos arriba. Si no tiene hijos, no descuenta nada (porque es una oferta, no un producto físico).
            if (!$descontado_ingredientes) {
                // No hacemos nada. El combo es virtual.
            }

        } else {
            // Producto Normal
            $conexion->prepare("UPDATE productos SET stock_actual = stock_actual - ? WHERE id = ?")->execute([$item['cantidad'], $item['id']]);
        }
    }

    // ---------------------------------------------------------
    // 7. PAGOS MIXTOS / CTA CTE / PUNTOS (Igual que antes)
    // ---------------------------------------------------------
    if($metodo === 'Mixto' && !empty($pagos_mixtos)) {
        $stmtMix = $conexion->prepare("INSERT INTO pagos_ventas (id_venta, metodo_pago, monto) VALUES (?, ?, ?)");
        if (is_string($pagos_mixtos)) $pagos_mixtos = json_decode($pagos_mixtos, true);
        foreach($pagos_mixtos as $metodo_nombre => $monto) {
            if($monto > 0) $stmtMix->execute([$venta_id, $metodo_nombre, $monto]);
        }
    }

    if ($id_cliente > 1) { 
        if ($metodo === 'CtaCorriente') {
            $monto_a_deber = $total - $saldo_favor_usado; 
            if($monto_a_deber > 0) {
                 $conexion->prepare("INSERT INTO movimientos_cc (id_cliente, id_venta, id_usuario, tipo, monto, descripcion, fecha) VALUES (?, ?, ?, 'debe', ?, 'Compra Fiado Ticket #$venta_id', ?)")->execute([$id_cliente, $venta_id, $user_id, $monto_a_deber, $fecha_actual]);
            }
        }
        
        $conf = $conexion->query("SELECT dinero_por_punto FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        $ratio = floatval($conf['dinero_por_punto'] ?? 100);
        if ($ratio > 0.1) {
            $puntos_nuevos = floor($total / $ratio);
            if ($puntos_nuevos > 0) {
                $conexion->prepare("UPDATE clientes SET puntos_acumulados = puntos_acumulados + ? WHERE id = ?")->execute([$puntos_nuevos, $id_cliente]);
            }
        }
        
        if ($saldo_favor_usado > 0) {
            $conexion->prepare("UPDATE clientes SET saldo_favor = saldo_favor - ? WHERE id = ?")->execute([$saldo_favor_usado, $id_cliente]);
            $conexion->prepare("INSERT INTO movimientos_cc (id_cliente, id_venta, id_usuario, tipo, monto, descripcion, fecha) VALUES (?, ?, ?, 'haber', ?, 'Uso Saldo a Favor', ?)")->execute([$id_cliente, $venta_id, $user_id, $saldo_favor_usado * -1, $fecha_actual]);
        }

        if ($pago_deuda > 0) {
            $concepto = "Pago a cuenta en Ticket #" . $venta_id;
            $conexion->prepare("INSERT INTO movimientos_cc (id_cliente, id_venta, id_usuario, tipo, monto, descripcion, fecha) VALUES (?, ?, ?, 'haber', ?, ?, ?)")->execute([$id_cliente, $venta_id, $user_id, $pago_deuda, $concepto, $fecha_actual]);
        }
    }
    
    // Auditoría
    $detalles_audit = "Venta #$venta_id | Total: $$total | Cliente ID: $id_cliente";
    if($desc_manual_monto > 0) $detalles_audit .= " | Desc.Manual: $$desc_manual_monto";
    $conexion->prepare("INSERT INTO auditoria (fecha, id_usuario, accion, detalles) VALUES (?, ?, 'VENTA_REALIZADA', ?)")->execute([$fecha_actual, $user_id, $detalles_audit]);

    $conexion->commit();
    echo json_encode(['status' => 'success', 'id_venta' => $venta_id, 'msg' => 'Venta procesada correctamente']);

} catch (Exception $e) {
    if ($conexion->inTransaction()) {
        $conexion->rollBack();
    }
    // Devolvemos el error en JSON para que el frontend lo muestre en alerta roja
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
?>