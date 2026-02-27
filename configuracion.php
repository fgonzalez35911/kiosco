<?php
// configuracion.php - VERSIÓN CORREGIDA CON SELECTOR DE COLOR
session_start();
if (empty($_SESSION['csrf_token'])) { 
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); 
}

// Buscador de conexión estándar
$rutas_db = [__DIR__ . '/db.php', __DIR__ . '/includes/db.php', 'db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

// --- 1. PROCESAR GUARDADO CONFIGURACIÓN GENERAL ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_general'])) {
    if (!$es_admin) {
        if (isset($_POST['nombre_negocio']) && !in_array('conf_datos_negocio', $permisos)) die("Sin permiso para datos del negocio.");
        if (isset($_POST['modulo_clientes']) && !in_array('conf_modulos', $permisos)) die("Sin permiso para módulos.");
        if (isset($_POST['stock_global_valor']) && !in_array('conf_alerta_stock', $permisos)) die("Sin permiso para alertas de stock.");
        if (isset($_POST['ticket_modo']) && !in_array('conf_comprobante', $permisos)) die("Sin permiso para modo comprobante.");
        if (isset($_POST['redondeo_auto']) && !in_array('conf_caja', $permisos)) die("Sin permiso para ajustes de caja.");
        if (isset($_POST['mp_access_token']) && !in_array('conf_mercadopago', $permisos)) die("Sin permiso para Mercado Pago.");
    }

    try {
        // 1. OBTENER DATOS ACTUALES PARA COMPARACIÓN QUIRÚRGICA
        $old = $conexion->query("SELECT * FROM configuracion LIMIT 1")->fetch(PDO::FETCH_ASSOC);

        // 2. CAPTURAR NUEVOS VALORES
        $n = [
            'nombre_negocio' => trim($_POST['nombre_negocio']),
            'direccion_local' => trim($_POST['direccion']),
            'telefono_whatsapp' => trim($_POST['telefono']),
            'whatsapp_pedidos' => trim($_POST['whatsapp_pedidos']),
            'cuit' => trim($_POST['cuit']),
            'mensaje_ticket' => trim($_POST['mensaje_ticket']),
            'dinero_por_punto' => floatval($_POST['dinero_por_punto'] ?? 100),
            'dias_alerta_vencimiento' => intval($_POST['dias_alerta_vencimiento'] ?? 30),
            'color_barra_nav' => $_POST['color_principal'] ?? '#102A57',
            'modulo_clientes' => isset($_POST['modulo_clientes']) ? 1 : 0,
            'modulo_stock' => isset($_POST['modulo_stock']) ? 1 : 0,
            'modulo_reportes' => isset($_POST['modulo_reportes']) ? 1 : 0,
            'modulo_fidelizacion' => isset($_POST['modulo_fidelizacion']) ? 1 : 0,
            'stock_use_global' => isset($_POST['stock_use_global']) ? 1 : 0,
            'stock_global_valor' => intval($_POST['stock_global_valor'] ?? 5),
            'ticket_modo' => $_POST['ticket_modo'] ?? 'afip',
            'redondeo_auto' => isset($_POST['redondeo_auto']) ? 1 : 0,
            'metodo_transferencia' => $_POST['metodo_transferencia'] ?? 'manual',
            'mp_access_token' => trim($_POST['mp_access_token'] ?? ''),
            'mp_user_id' => trim($_POST['mp_user_id'] ?? ''),
            'mp_pos_id' => trim($_POST['mp_pos_id'] ?? '')
        ];

        $logo_url = $_POST['logo_actual']; 
        if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $destino = 'uploads/logo_' . time() . '.png';
            if (move_uploaded_file($_FILES['logo']['tmp_name'], $destino)) { $logo_url = $destino; }
        }

        // 3. COMPARACIÓN DE CADA CAMPO (TRAZABILIDAD TOTAL)
        $cambios = [];
        $map = [
            'nombre_negocio' => 'Nombre', 'direccion_local' => 'Dirección', 'telefono_whatsapp' => 'Tel', 
            'whatsapp_pedidos' => 'WA Pedidos', 'cuit' => 'CUIT', 'mensaje_ticket' => 'Msg Ticket',
            'dinero_por_punto' => 'Valor Punto', 'dias_alerta_vencimiento' => 'Alerta Venc.',
            'color_barra_nav' => 'Color', 'modulo_clientes' => 'Mod. Clientes', 'modulo_stock' => 'Mod. Stock',
            'modulo_reportes' => 'Mod. Reportes', 'modulo_fidelizacion' => 'Mod. Puntos',
            'stock_use_global' => 'Uso Stock Global', 'stock_global_valor' => 'Valor Stock Global',
            'ticket_modo' => 'Modo Ticket', 'redondeo_auto' => 'Redondeo Auto', 'metodo_transferencia' => 'Recepción Transferencias',
            'mp_access_token' => 'MP Token', 'mp_user_id' => 'MP UserID', 'mp_pos_id' => 'MP POS'
        ];

        foreach ($map as $campo => $label) {
            if ($old[$campo] != $n[$campo]) {
                $v_old = ($old[$campo] === "1" || $old[$campo] === "0") ? ($old[$campo] ? 'ON' : 'OFF') : $old[$campo];
                $v_new = ($n[$campo] === 1 || $n[$campo] === 0) ? ($n[$campo] ? 'ON' : 'OFF') : $n[$campo];
                $cambios[] = "$label: $v_old -> $v_new";
            }
        }
        if ($old['logo_url'] != $logo_url) $cambios[] = "Logo: Actualizado";

        // 4. EJECUTAR UPDATE ÚNICO
        $sql = "UPDATE configuracion SET 
                nombre_negocio=?, direccion_local=?, telefono_whatsapp=?, whatsapp_pedidos=?, cuit=?, mensaje_ticket=?, 
                modulo_clientes=?, modulo_stock=?, modulo_reportes=?, modulo_fidelizacion=?, logo_url=?,
                dias_alerta_vencimiento=?, dinero_por_punto=?,
                stock_use_global=?, stock_global_valor=?, ticket_modo=?, redondeo_auto=?, metodo_transferencia=?,
                color_barra_nav=?, mp_access_token=?, mp_user_id=?, mp_pos_id=? WHERE id=1";

        $conexion->prepare($sql)->execute([
            $n['nombre_negocio'], $n['direccion_local'], $n['telefono_whatsapp'], $n['whatsapp_pedidos'], $n['cuit'], $n['mensaje_ticket'],
            $n['modulo_clientes'], $n['modulo_stock'], $n['modulo_reportes'], $n['modulo_fidelizacion'], $logo_url,
            $n['dias_alerta_vencimiento'], $n['dinero_por_punto'],
            $n['stock_use_global'], $n['stock_global_valor'], $n['ticket_modo'], $n['redondeo_auto'], $n['metodo_transferencia'],
            $n['color_barra_nav'], $n['mp_access_token'], $n['mp_user_id'], $n['mp_pos_id']
        ]);

        // AUTO-REPARAR CAJA MP (ESTO VINCULA EL ID TÉCNICO AL GUARDAR)
        if (!empty($n['mp_access_token']) && !empty($n['mp_pos_id'])) {
            autoRepararCajaMP($n['mp_access_token'], $n['mp_pos_id']);
        }
        
        // 5. REGISTRAR EN CAJA NEGRA
        if (!empty($cambios)) {
            $detalles_audit = implode(" | ", $cambios);
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'CONFIG_EDITADA', ?, NOW())")
                     ->execute([$_SESSION['usuario_id'], $detalles_audit]);
            
            $_SESSION['nombre_negocio'] = $n['nombre_negocio'];
            $_SESSION['color_barra_nav'] = $n['color_barra_nav'];
        }

        header("Location: configuracion.php?msg=guardado"); exit;
    } catch (Exception $e) { die("Error: " . $e->getMessage()); }
}

// --- 2. PROCESAR GUARDADO AFIP (AHORA CON AUDITORÍA INTELIGENTE) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_afip'])) {
    if (!$es_admin && !in_array('conf_afip', $permisos)) die("Sin permiso para configuración AFIP.");
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Error de seguridad: Solicitud no autorizada.");
    }

    // 1. Obtener datos viejos para comparar
    $stmtOldAfip = $conexion->query("SELECT cuit, punto_venta, modo FROM afip_config WHERE id=1");
    $oldAfip = $stmtOldAfip->fetch(PDO::FETCH_ASSOC);

    $cuit_afip = trim($_POST['cuit_afip']);
    $pto_vta = intval($_POST['punto_venta']);
    $modo = $_POST['modo_afip'];
    
    // 2. Detección exhaustiva de cambios
    $cambiosAfip = [];
    if($oldAfip['cuit'] != $cuit_afip) $cambiosAfip[] = "CUIT: " . ($oldAfip['cuit'] ?: 'S/N') . " -> " . $cuit_afip;
    if($oldAfip['punto_venta'] != $pto_vta) $cambiosAfip[] = "Pto Vta: " . ($oldAfip['punto_venta'] ?: '0') . " -> " . $pto_vta;
    if($oldAfip['modo'] != $modo) $cambiosAfip[] = "Modo: " . strtoupper($oldAfip['modo'] ?: 'N/A') . " -> " . strtoupper($modo);

    if (isset($_FILES['cert_crt']) && $_FILES['cert_crt']['error'] === UPLOAD_ERR_OK) {
        if(pathinfo($_FILES['cert_crt']['name'], PATHINFO_EXTENSION) == 'crt') {
            $ruta_crt = 'afip/certificado.crt';
            if(!is_dir('afip')) mkdir('afip');
            move_uploaded_file($_FILES['cert_crt']['tmp_name'], $ruta_crt);
            $conexion->prepare("UPDATE afip_config SET certificado_crt = ? WHERE id=1")->execute([$ruta_crt]);
            $cambiosAfip[] = "Certificado (.crt) Actualizado";
        }
    }

    if (isset($_FILES['cert_key']) && $_FILES['cert_key']['error'] === UPLOAD_ERR_OK) {
        if(pathinfo($_FILES['cert_key']['name'], PATHINFO_EXTENSION) == 'key') {
            $ruta_key = 'afip/privada.key';
            if(!is_dir('afip')) mkdir('afip');
            move_uploaded_file($_FILES['cert_key']['tmp_name'], $ruta_key);
            $conexion->prepare("UPDATE afip_config SET clave_key = ? WHERE id=1")->execute([$ruta_key]);
            $cambiosAfip[] = "Clave (.key) Actualizada";
        }
    }

    try {
        // 3. Ejecutar Update
        $conexion->prepare("UPDATE afip_config SET cuit=?, punto_venta=?, modo=? WHERE id=1")->execute([$cuit_afip, $pto_vta, $modo]);
        // Resetear tokens al cambiar credenciales
        $conexion->query("UPDATE afip_config SET token=NULL, sign=NULL WHERE id=1");

        // 4. REGISTRO EN AUDITORÍA INTELIGENTE
        if(!empty($cambiosAfip)) {
            $detalles_audit = "Ajustes AFIP | " . implode(" | ", $cambiosAfip);
            $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'CONFIG_AFIP', ?, NOW())")
                     ->execute([$_SESSION['usuario_id'], $detalles_audit]);
        }

        header("Location: configuracion.php?msg=afip_ok"); 
        exit;
    } catch (Exception $e) {
        die("Error al guardar configuración AFIP: " . $e->getMessage());
    }
}

// 3. OBTENER DATOS
$conf = $conexion->query("SELECT * FROM configuracion LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$afip = $conexion->query("SELECT * FROM afip_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);

$color_sistema = '#102A57';
try {
    $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1");
    if ($resColor) {
        $dataC = $resColor->fetch(PDO::FETCH_ASSOC);
        if (isset($dataC['color_barra_nav'])) $color_sistema = $dataC['color_barra_nav'];
    }
} catch (Exception $e) { }

include 'includes/layout_header.php'; ?></div>

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden;">
    <i class="bi bi-gear bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Panel de Configuración</h2>
                <p class="opacity-75 mb-0 text-white small text-white">Gestión integral de parámetros institucionales y AFIP.</p>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Aviso Vencimiento</div>
                        <div class="widget-value text-white">
                            <?php echo $conf['dias_alerta_vencimiento'] ?? 30; ?> <small class="fs-6 opacity-75">días</small>
                        </div>
                    </div>
                    <div class="icon-box bg-danger bg-opacity-10 text-danger">
                        <i class="bi bi-calendar-x"></i>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Modo Facturación</div>
                        <div class="widget-value <?php echo ($afip['modo'] == 'produccion') ? 'text-danger' : 'text-warning'; ?>" style="font-size: 1.1rem; padding-top: 8px;">
                            <?php echo ($afip['modo'] == 'produccion') ? 'MODO REAL (AFIP)' : 'MODO PRUEBAS'; ?>
                        </div>
                    </div>
                    <div class="icon-box <?php echo ($afip['modo'] == 'produccion') ? 'bg-danger' : 'bg-warning'; ?> bg-opacity-10 <?php echo ($afip['modo'] == 'produccion') ? 'text-danger' : 'text-warning'; ?>">
                        <i class="bi bi-shield-check"></i>
                    </div>
                </div>
            </div>

            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Valor del Punto</div>
                        <div class="widget-value text-white">$<?php echo number_format($conf['dinero_por_punto'], 0, ',', '.'); ?></div>
                    </div>
                    <div class="icon-box bg-info bg-opacity-10 text-info">
                        <i class="bi bi-star-fill"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container py-5">
    <ul class="nav nav-tabs mb-4" id="configTab" role="tablist">
        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#general">🏢 General</button></li>
        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#afip">🧾 Facturación AFIP</button></li>
    </ul>

    <div class="tab-content">
        <div class="tab-pane fade show active" id="general">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm rounded-4 p-4">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="guardar_general" value="1">
                                <input type="hidden" name="logo_actual" value="<?php echo $conf['logo_url']; ?>">

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="small fw-bold">Nombre del Negocio</label>
                                    <input type="text" name="nombre_negocio" class="form-control" value="<?php echo $conf['nombre_negocio']; ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold">CUIT</label>
                                    <input type="text" name="cuit" class="form-control" value="<?php echo $conf['cuit']; ?>">
                                </div>
                                <div class="col-12">
                                    <label class="small fw-bold">Dirección Física</label>
                                    <input type="text" name="direccion" class="form-control" value="<?php echo $conf['direccion_local']; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold">Teléfono General</label>
                                    <input type="text" name="telefono" class="form-control" value="<?php echo $conf['telefono_whatsapp']; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold text-success">WhatsApp Pedidos (Revista)</label>
                                    <input type="text" name="whatsapp_pedidos" class="form-control" value="<?php echo $conf['whatsapp_pedidos']; ?>">
                                </div>
                                <div class="col-12">
                                    <label class="small fw-bold">Mensaje en Ticket</label>
                                    <textarea name="mensaje_ticket" class="form-control" rows="2"><?php echo $conf['mensaje_ticket']; ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold">Valor del Punto ($)</label>
                                    <input type="number" step="0.01" name="dinero_por_punto" class="form-control" value="<?php echo $conf['dinero_por_punto']; ?>">
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold">Días Alerta Vencimiento</label>
                                    <input type="number" name="dias_alerta_vencimiento" class="form-control" value="<?php echo $conf['dias_alerta_vencimiento']; ?>">
                                </div>
                                <div class="col-12"><hr></div>
                                
                                <div class="col-md-6">
                                    <label class="small fw-bold">Logo del Ticket</label>
                                    <input type="file" name="logo" class="form-control">
                                    <?php if($conf['logo_url']): ?>
                                        <img src="<?php echo $conf['logo_url']; ?>" class="mt-2 rounded shadow-sm" style="height: 40px;">
                                    <?php endif; ?>
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold">Color del Sistema (HEX / Selector)</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color" id="colorPicker" value="<?php echo $color_sistema; ?>" style="width: 60px; flex: none;" title="Elegí un color">
                                        <input type="text" name="color_principal" id="colorHex" class="form-control fw-bold" value="<?php echo $color_sistema; ?>" placeholder="#102A57" maxlength="7">
                                        <span class="input-group-text small bg-white text-muted"><i class="bi bi-palette-fill"></i></span>
                                    </div>
                                    <small class="text-muted" style="font-size: 0.7rem;">Podés pegar tu código HEX (ej: #28a745) o usar el selector.</small>
                                </div>

                                <div class="col-md-12">
                                    <hr>
                                    <label class="small fw-bold mb-2 d-block">Módulos y Alertas</label>
                                    <div class="d-flex flex-wrap gap-4">
                                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="modulo_stock" <?php echo $conf['modulo_stock']?'checked':''; ?>><label class="small ms-2">Stock</label></div>
                                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="modulo_clientes" <?php echo $conf['modulo_clientes']?'checked':''; ?>><label class="small ms-2">Clientes</label></div>
                                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="modulo_reportes" <?php echo $conf['modulo_reportes']?'checked':''; ?>><label class="small ms-2">Reportes</label></div>
                                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="modulo_fidelizacion" <?php echo $conf['modulo_fidelizacion']?'checked':''; ?>><label class="small ms-2">Fidelización</label></div>
                                    </div>
                                    <hr class="my-2">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="stock_use_global" <?php echo $conf['stock_use_global']?'checked':''; ?>>
                                        <label class="small ms-2 fw-bold text-primary">Usar Alerta Stock Global</label>
                                    </div>
                                    <div class="input-group input-group-sm mt-1" style="max-width: 300px;">
                                        <span class="input-group-text">Avisar con:</span>
                                        <input type="number" name="stock_global_valor" class="form-control" value="<?php echo $conf['stock_global_valor']; ?>">
                                        <span class="input-group-text">unidades</span>
                                    </div>
                                </div>

                                <div class="col-12"><hr></div>
                                <div class="col-md-6">
                                    <label class="small fw-bold">Modo de Comprobante</label>
                                    <select name="ticket_modo" class="form-select form-select-sm">
                                        <option value="afip" <?php echo ($conf['ticket_modo']=='afip')?'selected':''; ?>>Factura Electrónica (AFIP)</option>
                                        <option value="interno" <?php echo ($conf['ticket_modo']=='interno')?'selected':''; ?>>Ticket Interno (No Fiscal)</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="small fw-bold">Ajustes de Caja</label>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" name="redondeo_auto" <?php echo $conf['redondeo_auto']?'checked':''; ?>>
                                        <label class="small ms-2">Redondeo automático en ventas</label>
                                    </div>
                                </div>

                                <div class="col-12"><hr><h6 class="fw-bold text-primary"><i class="bi bi-bank2"></i> Recepción de Transferencias (Alias / CVU)</h6></div>
                                <div class="col-md-12 mb-2">
                                    <div class="p-3 border rounded-3 bg-light">
                                        <label class="small fw-bold mb-2">¿Cómo querés validar las transferencias manuales de tus clientes?</label>
                                        <select name="metodo_transferencia" class="form-select border-primary fw-bold text-primary">
                                            <option value="manual" <?php echo (!isset($conf['metodo_transferencia']) || $conf['metodo_transferencia']=='manual')?'selected':''; ?>>👁️ Validación Manual (Miro mi celular y confirmo en caja)</option>
                                            <option value="ocr" <?php echo ($conf['metodo_transferencia']=='ocr')?'selected':''; ?>>📷 Escáner de Pantalla Inteligente (Con IA y resguardo de foto)</option>
                                            <option value="webhook" disabled>📱 Celular Esclavo (100% Automático) [EN DESARROLLO]</option>
                                        </select>
                                        <small class="text-muted d-block mt-2"><i class="bi bi-info-circle"></i> El "Escáner de Pantalla" abrirá la cámara de la caja para fotografiar y leer el comprobante del cliente automáticamente.</small>
                                    </div>
                                </div>

                                <div class="col-12"><hr><h6 class="fw-bold"><i class="bi bi-qr-code"></i> Configuración Mercado Pago (QR Dinámico)</h6></div>
                                <div class="col-md-6">
                                    <label class="small fw-bold">Access Token (Producción)</label>
                                    <input type="text" name="mp_access_token" class="form-control form-control-sm" value="<?php echo $conf['mp_access_token']; ?>" placeholder="APP_USR-...">
                                </div>
                                <div class="col-md-3">
                                    <label class="small fw-bold">User ID (Collector ID)</label>
                                    <input type="text" name="mp_user_id" class="form-control form-control-sm" value="<?php echo $conf['mp_user_id']; ?>">
                                </div>
                                <div class="col-md-3">
                                    <label class="small fw-bold">ID Punto de Venta (External ID)</label>
                                    <input type="text" name="mp_pos_id" class="form-control form-control-sm" value="<?php echo $conf['mp_pos_id']; ?>">
                                </div>

                                <div class="col-12 mt-4">
                                    <button type="submit" name="guardar_general" class="btn btn-primary">GUARDAR CONFIGURACIÓN</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm rounded-4 p-4 text-center">
                        <i class="bi bi-cloud-upload text-primary fs-1 mb-3"></i>
                        <h5 class="fw-bold">Importación Masiva</h5>
                        <p class="text-muted small">Carga tus productos y precios desde un archivo Excel/CSV rápidamente.</p>
                        <a href="importador_maestro.php" class="btn btn-outline-primary rounded-pill fw-bold">IR AL IMPORTADOR</a>
                        <div class="mt-4 pt-4 border-top">
                            <h5 class="fw-bold"><i class="bi bi-database-down"></i> Respaldo</h5>
                            <p class="text-muted small">Descargá una copia de seguridad de toda tu base de datos (.SQL).</p>
                            <a href="generar_backup.php" class="btn btn-dark btn-sm rounded-pill fw-bold w-100">DESCARGAR BACKUP</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="afip">
            <div class="card border-0 shadow-sm rounded-4 p-4">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="guardar_afip" value="1">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="small fw-bold">CUIT Titular (Sin guiones)</label>
                            <input type="text" name="cuit_afip" class="form-control" value="<?php echo $afip['cuit']; ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold">Punto de Venta</label>
                            <input type="number" name="punto_venta" class="form-control" value="<?php echo $afip['punto_venta']; ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="small fw-bold">Modo AFIP</label>
                            <select name="modo_afip" class="form-select">
                                <option value="homologacion" <?php echo ($afip['modo']=='homologacion')?'selected':''; ?>>🛠️ Homologación (Pruebas)</option>
                                <option value="produccion" <?php echo ($afip['modo']=='produccion')?'selected':''; ?>>✅ Producción (Real)</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold">Certificado (.crt)</label>
                            <input type="file" name="cert_crt" class="form-control" accept=".crt">
                        </div>
                        <div class="col-md-6">
                            <label class="small fw-bold">Clave (.key)</label>
                            <input type="file" name="cert_key" class="form-control" accept=".key">
                        </div>
                        <div class="col-12 mt-4">
                            <button type="submit" name="guardar_afip" class="btn btn-dark">ACTUALIZAR DATOS AFIP</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
    // Sincronización del selector de color con el input de texto
    document.addEventListener('DOMContentLoaded', function() {
        const cp = document.getElementById('colorPicker');
        const ch = document.getElementById('colorHex');
        if(cp && ch) {
            cp.addEventListener('input', () => ch.value = cp.value.toUpperCase());
            ch.addEventListener('input', () => { 
                if(/^#[0-9A-F]{6}$/i.test(ch.value)) cp.value = ch.value; 
            });
        }
    });
</script>

<?php include 'includes/layout_footer.php'; ?>

<?php if(isset($_GET['msg'])): ?>
<script>
    window.addEventListener('load', function() {
        const m = "<?php echo $_GET['msg']; ?>";
        if(m === 'guardado') Swal.fire({ icon: 'success', title: '¡Excelente!', text: 'Los cambios se aplicaron correctamente en todo el sistema.', timer: 3000, showConfirmButton: false });
        if(m === 'afip_ok') Swal.fire({ icon: 'success', title: 'AFIP', text: 'Datos de facturación electrónica actualizados.', timer: 3000, showConfirmButton: false });
    });
</script>
<?php endif; 

// FUNCIÓN PARA VINCULAR LA CAJA TÉCNICAMENTE (EXTERNAL_ID)
function autoRepararCajaMP($token, $externalIdDeseado) {
    $ch = curl_init("https://api.mercadopago.com/pos");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
    $res = json_decode(curl_exec($ch), true);

    if (isset($res['results'][0]['id'])) {
        $internal_id = $res['results'][0]['id'];
        $ch2 = curl_init("https://api.mercadopago.com/pos/$internal_id");
        curl_setopt($ch2, CURLOPT_CUSTOMREQUEST, "PUT");
        curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode(["external_id" => $externalIdDeseado]));
        curl_setopt($ch2, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token", "Content-Type: application/json"]);
        curl_exec($ch2);
        return true;
    }
    return false;
}

?>