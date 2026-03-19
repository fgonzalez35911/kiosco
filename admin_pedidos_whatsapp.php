<?php
require_once 'includes/layout_header.php';
require_once 'includes/db.php';

// 1. BLINDAJES Y PREPARACIÓN DE TABLAS
try { $conexion->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } catch(Exception $e){}
try { $conexion->exec("ALTER TABLE pedidos_whatsapp MODIFY COLUMN estado VARCHAR(30) DEFAULT 'pendiente'"); } catch(Exception $e){}
try { $conexion->exec("ALTER TABLE pedidos_whatsapp ADD COLUMN IF NOT EXISTS motivo_cancelacion VARCHAR(255) NULL"); } catch(Exception $e){}
try { $conexion->exec("UPDATE productos SET stock_reservado = 0 WHERE stock_reservado IS NULL"); } catch(Exception $e){}

// 2. LÓGICA DE PROCESAMIENTO (POST)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion'])) {
    $id = intval($_POST['id_pedido']);
    $accion = $_POST['accion'];
    $motivo = $_POST['motivo'] ?? '';
    $fecha_retiro = $_POST['fecha_retiro'] ?? '';

    $stmt = $conexion->prepare("SELECT p.*, c.email as cliente_email FROM pedidos_whatsapp p LEFT JOIN clientes c ON p.id_cliente = c.id WHERE p.id = ?");
    $stmt->execute([$id]);
    $pedido = $stmt->fetch(PDO::FETCH_OBJ);

    if ($pedido) {
        $id_us = $_SESSION['usuario_id'] ?? 1;
        $c_rubro = $conexion->query("SELECT tipo_negocio FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
        $rubro = $c_rubro['tipo_negocio'] ?? 'kiosco';
        $fecha_php = date('Y-m-d H:i:s'); 

        if ($pedido->estado === 'pendiente') {
            if ($accion === 'aprobar') {
                $conexion->beginTransaction();
                try {
                    $detalles = $conexion->prepare("SELECT * FROM pedidos_whatsapp_detalle WHERE id_pedido = ?");
                    $detalles->execute([$id]);
                    while ($item = $detalles->fetch(PDO::FETCH_OBJ)) {
                        $upd = $conexion->prepare("UPDATE productos SET stock_actual = COALESCE(stock_actual,0) - ?, stock_reservado = COALESCE(stock_reservado,0) - ? WHERE id = ?");
                        $upd->execute([$item->cantidad, $item->cantidad, $item->id_producto]);
                    }
                    $conexion->prepare("UPDATE pedidos_whatsapp SET estado = 'aprobado', fecha_retiro = ? WHERE id = ?")->execute([$fecha_retiro, $id]);
                    $conexion->commit();
                    echo "<script>location.href='acciones/enviar_email_pedido.php?id=$id&status=aprobado';</script>";
                    exit;
                } catch (Exception $e) { 
                    $conexion->rollBack(); 
                    die("Error al Aprobar: " . $e->getMessage());
                }
            } else if ($accion === 'rechazar') { // CULPA DEL LOCAL
                $conexion->beginTransaction();
                try {
                    $detalles = $conexion->prepare("SELECT * FROM pedidos_whatsapp_detalle WHERE id_pedido = ?");
                    $detalles->execute([$id]);
                    while ($item = $detalles->fetch(PDO::FETCH_OBJ)) {
                        $conexion->prepare("UPDATE productos SET stock_reservado = COALESCE(stock_reservado,0) - ? WHERE id = ?")->execute([$item->cantidad, $item->id_producto]);
                    }
                    $conexion->prepare("UPDATE pedidos_whatsapp SET estado = 'rechazado', motivo_cancelacion = ? WHERE id = ?")->execute([$motivo, $id]);
                    $conexion->commit();
                    echo "<script>location.href='acciones/enviar_email_pedido.php?id=$id&status=rechazado';</script>";
                    exit;
                } catch (Exception $e) { $conexion->rollBack(); }
            }
        } else if ($pedido->estado === 'aprobado') {
            if ($accion === 'entregado') {
                $conexion->beginTransaction();
                try {
                    $conexion->prepare("UPDATE pedidos_whatsapp SET estado = 'entregado' WHERE id = ?")->execute([$id]);
                    
                    $stmtC = $conexion->prepare("SELECT id FROM cajas_sesion WHERE id_usuario = ? AND estado = 'abierta' ORDER BY id DESC LIMIT 1");
                    $stmtC->execute([$id_us]);
                    $caja_abierta = $stmtC->fetchColumn();
                    if(!$caja_abierta) {
                        $caja_abierta = $conexion->query("SELECT id FROM cajas_sesion WHERE estado = 'abierta' ORDER BY id DESC LIMIT 1")->fetchColumn();
                    }
                    $caja_val = $caja_abierta ? $caja_abierta : null;
                    $id_cli = !empty($pedido->id_cliente) ? $pedido->id_cliente : 1;
                    
                    $stmtV = $conexion->prepare("INSERT INTO ventas (id_caja_sesion, id_usuario, id_cliente, fecha, total, metodo_pago, estado, tipo_negocio) VALUES (?, ?, ?, ?, ?, 'Efectivo', 'completada', ?)");
                    $stmtV->execute([$caja_val, $id_us, $id_cli, $fecha_php, $pedido->total, $rubro]);
                    $id_venta = $conexion->lastInsertId();
                    
                    $detalles = $conexion->prepare("SELECT d.*, p.precio_costo FROM pedidos_whatsapp_detalle d JOIN productos p ON d.id_producto = p.id WHERE d.id_pedido = ?");
                    $detalles->execute([$id]);
                    $insDet = $conexion->prepare("INSERT INTO detalle_ventas (id_venta, id_producto, cantidad, precio_historico, costo_historico, subtotal, tipo_negocio) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    
                    $ganancia_tot = 0;
                    while ($d = $detalles->fetch(PDO::FETCH_OBJ)) {
                        $costo = floatval($d->precio_costo ?? 0);
                        $ganancia_item = ($d->precio_unitario - $costo) * $d->cantidad;
                        $ganancia_tot += $ganancia_item;
                        $insDet->execute([$id_venta, $d->id_producto, $d->cantidad, $d->precio_unitario, $costo, $d->subtotal, $rubro]);
                    }
                    
                    try { $conexion->exec("UPDATE ventas SET ganancia = $ganancia_tot WHERE id = $id_venta"); } catch(Exception $e){}
                    
                    $conexion->commit();
                    echo "<script>location.href='admin_pedidos_whatsapp.php?msg=EstadoActualizado';</script>";
                    exit;
                } catch (Exception $e) { 
                    $conexion->rollBack(); 
                    die("Error al Entregar: " . $e->getMessage());
                }
            } else if ($accion === 'extender') {
                $conexion->prepare("UPDATE pedidos_whatsapp SET fecha_retiro = ? WHERE id = ?")->execute([$fecha_retiro, $id]);
                echo "<script>location.href='admin_pedidos_whatsapp.php?msg=EstadoActualizado';</script>";
                exit;
            } else if ($accion === 'liberar') { // CULPA DEL CLIENTE
                $conexion->beginTransaction();
                try {
                    $detalles = $conexion->prepare("SELECT * FROM pedidos_whatsapp_detalle WHERE id_pedido = ?");
                    $detalles->execute([$id]);
                    while ($item = $detalles->fetch(PDO::FETCH_OBJ)) {
                        $conexion->prepare("UPDATE productos SET stock_actual = COALESCE(stock_actual,0) + ? WHERE id = ?")->execute([$item->cantidad, $item->id_producto]);
                    }
                    $conexion->prepare("UPDATE pedidos_whatsapp SET estado = 'no_retirado', motivo_cancelacion = ? WHERE id = ?")->execute([$motivo, $id]);
                    $conexion->commit();
                    echo "<script>location.href='acciones/enviar_email_pedido.php?id=$id&status=no_retirado';</script>";
                    exit;
                } catch (Exception $e) { $conexion->rollBack(); }
            }
        }
    }
}

$pedidos = $conexion->query("SELECT * FROM pedidos_whatsapp ORDER BY fecha_pedido DESC")->fetchAll(PDO::FETCH_OBJ);
?>

<div class="container-fluid py-4">
    <h2 class="font-cancha mb-4"><i class="bi bi-envelope-paper text-primary"></i> Gestión de Pedidos Web</h2>
    
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-0">
            <table class="table table-hover align-middle mb-0">
                <thead class="bg-light">
                    <tr>
                        <th>ID</th><th>Fecha</th><th>Cliente</th><th>Total</th><th>Estado</th><th>Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($pedidos as $p): ?>
                    <tr>
                        <td>#<?php echo $p->id; ?></td>
                        <td><?php echo date('d/m/y H:i', strtotime($p->fecha_pedido)); ?></td>
                        <td>
                            <div class="fw-bold"><?php echo htmlspecialchars($p->nombre_cliente); ?></div>
                            <small class="text-muted"><?php echo htmlspecialchars($p->email_cliente ?? ''); ?></small>
                            <button class="btn btn-link btn-sm p-0 ms-1 text-primary" onclick="verHistorial('<?php echo htmlspecialchars($p->email_cliente ?? ''); ?>')"><i class="bi bi-person-lines-fill"></i></button>
                        </td>
                        <td class="fw-bold text-success">$<?php echo number_format($p->total, 2); ?></td>
                        <td>
                            <?php
                                $badge_class = 'bg-secondary';
                                if($p->estado == 'pendiente') $badge_class = 'bg-warning text-dark';
                                if($p->estado == 'aprobado') $badge_class = 'bg-primary';
                                if($p->estado == 'entregado') $badge_class = 'bg-success';
                                if($p->estado == 'rechazado') $badge_class = 'bg-danger';
                                if($p->estado == 'no_retirado') $badge_class = 'bg-dark';
                            ?>
                            <span class="badge <?php echo $badge_class; ?>"><?php echo strtoupper($p->estado); ?></span>
                        </td>
                        <td>
                            <button class="btn btn-sm btn-outline-dark" onclick="verDetalle(<?php echo $p->id; ?>)"><i class="bi bi-eye"></i></button>
                            
                            <?php if($p->estado == 'pendiente'): ?>
                                <button class="btn btn-sm btn-success" onclick="procesar(<?php echo $p->id; ?>, 'aprobar')"><i class="bi bi-check-lg"></i></button>
                                <button class="btn btn-sm btn-danger" onclick="procesar(<?php echo $p->id; ?>, 'rechazar')"><i class="bi bi-x"></i></button>
                            <?php endif; ?>

                            <?php if($p->estado == 'aprobado'): ?>
                                <button class="btn btn-sm btn-success" onclick="procesarAprobado(<?php echo $p->id; ?>, 'entregado')"><i class="bi bi-bag-check-fill"></i></button>
                                <button class="btn btn-sm btn-warning text-dark" onclick="procesarAprobado(<?php echo $p->id; ?>, 'extender')"><i class="bi bi-clock-history"></i></button>
                                <button class="btn btn-sm btn-danger" onclick="procesarAprobado(<?php echo $p->id; ?>, 'liberar')"><i class="bi bi-arrow-counterclockwise"></i></button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
function procesar(id, accion) {
    let titulo = accion === 'aprobar' ? '¿Aprobar Pedido?' : '❌ Rechazar Pedido (Problema del Local)';
    let html = '';
    
    if(accion === 'aprobar') {
        html = '<label>Fecha/Hora de Retiro:</label><input type="datetime-local" id="f_retiro" class="form-control">';
    } else {
        html = `<label class="fw-bold mb-2">Motivo de rechazo:</label>
                <select id="motivo_canc" class="form-select mb-2">
                    <option value="Falta de stock físico">Falta de stock físico</option>
                    <option value="Precios desactualizados">Precios desactualizados</option>
                    <option value="No podemos prepararlo a tiempo">No podemos prepararlo a tiempo</option>
                </select>
                <small class="text-muted">Se le informará al cliente por correo.</small>`;
    }

    Swal.fire({
        title: titulo,
        html: html,
        icon: accion === 'aprobar' ? 'info' : 'warning',
        showCancelButton: true,
        confirmButtonText: 'Confirmar',
        preConfirm: () => {
            if(accion === 'aprobar') {
                const f = document.getElementById('f_retiro').value;
                if(!f) return Swal.showValidationMessage('Elegí una fecha de retiro');
                return { fecha: f, motivo: '' };
            } else {
                return { fecha: '', motivo: document.getElementById('motivo_canc').value };
            }
        }
    }).then((result) => {
        if (result.isConfirmed) {
            enviarFormulario(id, accion, result.value.fecha, result.value.motivo);
        }
    });
}

function procesarAprobado(id, accion) {
    let titulo = '';
    let texto = '';
    let html = '';
    
    if (accion === 'entregado') {
        titulo = '¿Marcar como Entregado?';
        texto = 'El pedido pasará al historial de ventas. El dinero ingresará a caja.';
    } else if (accion === 'liberar') {
        titulo = '🚫 Cancelar Reserva (Culpa del Cliente)';
        html = `<label class="fw-bold mb-2">Motivo:</label>
                <select id="motivo_canc_lib" class="form-select mb-2">
                    <option value="El cliente no pasó a retirar el pedido">El cliente no pasó a retirar el pedido</option>
                    <option value="El cliente avisó que ya no lo quiere">El cliente avisó que ya no lo quiere</option>
                </select>`;
    } else if (accion === 'extender') {
        titulo = 'Alargar Plazo';
        html = '<label class="fw-bold mb-2">Nueva fecha límite:</label><input type="datetime-local" id="f_retiro_ext" class="form-control">';
    }

    Swal.fire({
        title: titulo,
        text: texto,
        html: html,
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Confirmar',
        preConfirm: () => {
            if(accion === 'extender') {
                const f = document.getElementById('f_retiro_ext').value;
                if(!f) return Swal.showValidationMessage('Elegí una nueva fecha');
                return { fecha: f, motivo: '' };
            } else if (accion === 'liberar') {
                return { fecha: '', motivo: document.getElementById('motivo_canc_lib').value };
            }
            return { fecha: '', motivo: '' };
        }
    }).then((result) => {
        if (result.isConfirmed) {
            enviarFormulario(id, accion, result.value.fecha, result.value.motivo);
        }
    });
}

function enviarFormulario(id, accion, fecha, motivo) {
    let f = document.createElement('form');
    f.method = 'POST';
    f.innerHTML = `<input type="hidden" name="id_pedido" value="${id}">
                   <input type="hidden" name="accion" value="${accion}">
                   <input type="hidden" name="fecha_retiro" value="${fecha}">
                   <input type="hidden" name="motivo" value="${motivo}">`;
    document.body.appendChild(f);
    f.submit();
}

function verDetalle(id) {
    Swal.fire({
        title: 'Detalle del Pedido #' + id,
        html: '<div id="detalle-contenido" class="text-center"><div class="spinner-border text-primary"></div></div>',
        width: '600px',
        showCloseButton: true,
        didOpen: () => {
            fetch('ajax_pedido_detalle.php?id=' + id)
                .then(r => r.text())
                .then(h => document.getElementById('detalle-contenido').innerHTML = h);
        }
    });
}

function verHistorial(email) {
    if(!email) return Swal.fire('Error', 'No hay correo registrado.', 'error');
    Swal.fire({
        title: 'Historial del Cliente',
        html: '<div id="historial-contenido" class="text-center"><div class="spinner-border text-primary"></div></div>',
        width: '500px',
        didOpen: () => {
            fetch('ajax_historial_cliente_wa.php?email=' + encodeURIComponent(email))
                .then(r => r.text())
                .then(h => document.getElementById('historial-contenido').innerHTML = h);
        }
    });
}
</script>

<?php if (isset($_GET['msg'])): ?>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        let msg = "<?php echo $_GET['msg']; ?>";
        if(msg === 'EmailEnviado') {
            Swal.fire({icon: 'success', title: 'Notificación Enviada', text: 'Se notificó al cliente: <?php echo $_GET['correo']; ?>'});
        } else if(msg === 'EstadoActualizado') {
            Swal.fire({icon: 'success', title: 'Operación Exitosa', text: 'El sistema fue actualizado correctamente.'});
        }
        window.history.replaceState({}, document.title, window.location.pathname);
    });
</script>
<?php endif; ?>
<?php require_once 'includes/layout_footer.php'; ?>