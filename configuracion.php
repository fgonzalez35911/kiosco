<?php
// configuracion.php - VERSIÓN ESTANDARIZADA VANGUARD PRO
session_start();
if (empty($_SESSION['csrf_token'])) { 
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); 
}

// Buscador de conexión estándar
$rutas_db = [__DIR__ . '/db.php', __DIR__ . '/includes/db.php', 'db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }
$rol_usuario = $_SESSION['rol'] ?? 3;
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = ($rol_usuario <= 2);

// --- CANDADO PRINCIPAL DE ACCESO ---
if (!$es_admin && !in_array('config_ver_panel', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}
// --- 1. PROCESAR GUARDADO CONFIGURACIÓN GENERAL ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_general'])) {
    if (!$es_admin) {
        if (isset($_POST['nombre_negocio']) && !in_array('config_datos_negocio', $permisos)) die("Sin permiso para datos del negocio.");
        if (isset($_POST['modulo_clientes']) && !in_array('config_modulos', $permisos)) die("Sin permiso para módulos.");
        if (isset($_POST['mp_access_token']) && !in_array('config_mercadopago', $permisos)) die("Sin permiso para Mercado Pago.");
    }

    try {
        $old = $conexion->query("SELECT * FROM configuracion LIMIT 1")->fetch(PDO::FETCH_ASSOC);

        $n = [
            'nombre_negocio' => trim($_POST['nombre_negocio']),
            'direccion_local' => trim($_POST['direccion']),
            'telefono_whatsapp' => trim($_POST['telefono']),
            'whatsapp_pedidos' => trim($_POST['whatsapp_pedidos']),
            'cuit' => trim($_POST['cuit']),
            'mensaje_ticket' => trim($_POST['mensaje_ticket']),
            'dinero_por_punto' => floatval($_POST['dinero_por_punto'] ?? 100),
            'dias_alerta_vencimiento' => intval($_POST['dias_alerta_vencimiento'] ?? 30),
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

        $sql = "UPDATE configuracion SET 
                nombre_negocio=?, direccion_local=?, telefono_whatsapp=?, whatsapp_pedidos=?, cuit=?, mensaje_ticket=?, 
                modulo_clientes=?, modulo_stock=?, modulo_reportes=?, modulo_fidelizacion=?, logo_url=?,
                dias_alerta_vencimiento=?, dinero_por_punto=?,
                stock_use_global=?, stock_global_valor=?, ticket_modo=?, redondeo_auto=?, metodo_transferencia=?,
                mp_access_token=?, mp_user_id=?, mp_pos_id=? WHERE id=1";

        $conexion->prepare($sql)->execute([
            $n['nombre_negocio'], $n['direccion_local'], $n['telefono_whatsapp'], $n['whatsapp_pedidos'], $n['cuit'], $n['mensaje_ticket'],
            $n['modulo_clientes'], $n['modulo_stock'], $n['modulo_reportes'], $n['modulo_fidelizacion'], $logo_url,
            $n['dias_alerta_vencimiento'], $n['dinero_por_punto'],
            $n['stock_use_global'], $n['stock_global_valor'], $n['ticket_modo'], $n['redondeo_auto'], $n['metodo_transferencia'],
            $n['mp_access_token'], $n['mp_user_id'], $n['mp_pos_id']
        ]);

        if (!empty($n['mp_access_token']) && !empty($n['mp_pos_id'])) {
            autoRepararCajaMP($n['mp_access_token'], $n['mp_pos_id']);
        }
        
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'CONFIG_EDITADA', 'Se actualizaron datos generales', NOW())")->execute([$_SESSION['usuario_id']]);
        $_SESSION['nombre_negocio'] = $n['nombre_negocio'];

        header("Location: configuracion.php?msg=guardado"); exit;
    } catch (Exception $e) { die("Error: " . $e->getMessage()); }
}

// --- 2. PROCESAR GUARDADO AFIP ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_afip'])) {
    if (!$es_admin && !in_array('config_afip', $permisos)) die("Sin permiso para configuración AFIP.");
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) { die("Error de seguridad."); }

    $cuit_afip = trim($_POST['cuit_afip']);
    $pto_vta = intval($_POST['punto_venta']);
    $modo = $_POST['modo_afip'];

    if (isset($_FILES['cert_crt']) && $_FILES['cert_crt']['error'] === UPLOAD_ERR_OK) {
        if(pathinfo($_FILES['cert_crt']['name'], PATHINFO_EXTENSION) == 'crt') {
            $ruta_crt = 'afip/certificado.crt';
            if(!is_dir('afip')) mkdir('afip');
            move_uploaded_file($_FILES['cert_crt']['tmp_name'], $ruta_crt);
            $conexion->prepare("UPDATE afip_config SET certificado_crt = ? WHERE id=1")->execute([$ruta_crt]);
        }
    }

    if (isset($_FILES['cert_key']) && $_FILES['cert_key']['error'] === UPLOAD_ERR_OK) {
        if(pathinfo($_FILES['cert_key']['name'], PATHINFO_EXTENSION) == 'key') {
            $ruta_key = 'afip/privada.key';
            if(!is_dir('afip')) mkdir('afip');
            move_uploaded_file($_FILES['cert_key']['tmp_name'], $ruta_key);
            $conexion->prepare("UPDATE afip_config SET clave_key = ? WHERE id=1")->execute([$ruta_key]);
        }
    }

    try {
        $conexion->prepare("UPDATE afip_config SET cuit=?, punto_venta=?, modo=? WHERE id=1")->execute([$cuit_afip, $pto_vta, $modo]);
        $conexion->query("UPDATE afip_config SET token=NULL, sign=NULL WHERE id=1");
        $conexion->prepare("INSERT INTO auditoria (id_usuario, accion, detalles, fecha) VALUES (?, 'CONFIG_AFIP', 'Actualización credenciales', NOW())")->execute([$_SESSION['usuario_id']]);

        header("Location: configuracion.php?msg=afip_ok"); exit;
    } catch (Exception $e) { die("Error: " . $e->getMessage()); }
}

// --- 3. PROCESAR GUARDADO PERSONALIZACIÓN (Incluye Color) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_personalizacion'])) {
    if (!$es_admin) die("Acceso denegado.");
    $color_sistema_nuevo = $_POST['color_principal'] ?? '#102A57';
    
    $sql = "UPDATE configuracion SET 
            label_seccion_1=?, label_seccion_2=?, label_seccion_3=?, label_punto_venta=?,
            label_productos=?, label_combos=?, label_clientes=?, label_proveedores=?, label_sorteos=?,
            label_recaudacion=?, label_gastos=?, label_aumentos=?, label_cupones=?, label_revista=?,
            label_reportes=?, label_config=?, label_usuarios=?, label_auditoria=?, label_importador=?,
            label_categorias=?, label_mermas=?, label_respaldo=?, color_barra_nav=? 
            WHERE id=1";
    $params = [
        $_POST['label_seccion_1'], $_POST['label_seccion_2'], $_POST['label_seccion_3'], $_POST['label_punto_venta'],
        $_POST['label_productos'], $_POST['label_combos'], $_POST['label_clientes'], $_POST['label_proveedores'], $_POST['label_sorteos'],
        $_POST['label_recaudacion'], $_POST['label_gastos'], $_POST['label_aumentos'], $_POST['label_cupones'], $_POST['label_revista'],
        $_POST['label_reportes'], $_POST['label_config'], $_POST['label_usuarios'], $_POST['label_auditoria'], $_POST['label_importador'],
        $_POST['label_categorias'], $_POST['label_mermas'], $_POST['label_respaldo'], $color_sistema_nuevo
    ];
    $conexion->prepare($sql)->execute($params);
    
    $_SESSION['color_barra_nav'] = $color_sistema_nuevo;
    header("Location: configuracion.php?msg=guardado"); exit;
}

// LECTURA DE DATOS (RESTAURADA)
$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$afip = $conexion->query("SELECT * FROM afip_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$color_sistema = $conf['color_barra_nav'] ?? '#102A57';

include 'includes/layout_header.php'; 

// --- DEFINICIÓN DEL BANNER DINÁMICO ESTANDARIZADO ---
$titulo = "Configuración del Sistema";
$subtitulo = "Gestión integral de parámetros, facturación y mística.";
$icono_bg = "bi-gear-fill";

$widgets = [
    ['label' => 'Valor del Punto', 'valor' => '$'.number_format($conf['dinero_por_punto'], 0, ',', '.'), 'icono' => 'bi-star-fill', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Modo Ticket', 'valor' => strtoupper($conf['ticket_modo']), 'icono' => 'bi-receipt', 'icon_bg' => 'bg-info bg-opacity-20'],
    ['label' => 'Estado AFIP', 'valor' => ($afip['modo'] == 'produccion') ? 'MODO REAL' : 'PRUEBAS', 'icono' => 'bi-shield-check', 'border' => ($afip['modo'] == 'produccion') ? 'border-danger' : 'border-warning', 'icon_bg' => ($afip['modo'] == 'produccion') ? 'bg-danger bg-opacity-20' : 'bg-warning bg-opacity-20']
];

include 'includes/componente_banner.php'; 
?>

<div class="container-fluid container-md pb-5 mt-n4 px-2 px-md-3" style="position: relative; z-index: 20;">
    
    <ul class="nav nav-tabs nav-fill bg-white shadow-sm rounded-top-4 border-0 mb-4" id="configTab" role="tablist">
        <li class="nav-item border-end"><button class="nav-link active fw-bold py-3 text-dark border-0 rounded-0" data-bs-toggle="tab" data-bs-target="#general"><i class="bi bi-building me-1 text-primary"></i> General</button></li>
        <li class="nav-item border-end"><button class="nav-link fw-bold py-3 text-dark border-0 rounded-0" data-bs-toggle="tab" data-bs-target="#afip"><i class="bi bi-receipt me-1 text-danger"></i> AFIP</button></li>
        <li class="nav-item"><button class="nav-link fw-bold py-3 text-dark border-0 rounded-0" data-bs-toggle="tab" data-bs-target="#personalizacion"><i class="bi bi-palette me-1 text-success"></i> Personalización</button></li>
    </ul>

    <div class="tab-content">
        
        <div class="tab-pane fade show active" id="general">
            <div class="row g-4">
                <div class="col-lg-8">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                        <input type="hidden" name="guardar_general" value="1">
                        <input type="hidden" name="logo_actual" value="<?php echo $conf['logo_url']; ?>">

                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white py-3 border-0 fw-bold text-primary"><i class="bi bi-shop me-2"></i>Datos Institucionales</div>
                            <div class="card-body bg-light rounded-bottom">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted text-uppercase mb-1">Nombre del Negocio</label>
                                        <input type="text" name="nombre_negocio" class="form-control shadow-sm" value="<?php echo $conf['nombre_negocio']; ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted text-uppercase mb-1">CUIT</label>
                                        <input type="text" name="cuit" class="form-control shadow-sm" value="<?php echo $conf['cuit']; ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="small fw-bold text-muted text-uppercase mb-1">Dirección Física</label>
                                        <input type="text" name="direccion" class="form-control shadow-sm" value="<?php echo $conf['direccion_local']; ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted text-uppercase mb-1">Teléfono General</label>
                                        <input type="text" name="telefono" class="form-control shadow-sm" value="<?php echo $conf['telefono_whatsapp']; ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-success text-uppercase mb-1"><i class="bi bi-whatsapp"></i> WA Pedidos (Revista)</label>
                                        <input type="text" name="whatsapp_pedidos" class="form-control border-success shadow-sm" value="<?php echo $conf['whatsapp_pedidos']; ?>">
                                    </div>
                                    <div class="col-12">
                                        <label class="small fw-bold text-muted text-uppercase mb-1">Mensaje en Ticket</label>
                                        <textarea name="mensaje_ticket" class="form-control shadow-sm" rows="2"><?php echo $conf['mensaje_ticket']; ?></textarea>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted text-uppercase mb-1">Logo del Ticket</label>
                                        <input type="file" name="logo" class="form-control shadow-sm">
                                        <?php if($conf['logo_url']): ?>
                                            <div class="mt-2"><img src="<?php echo $conf['logo_url']; ?>" class="rounded border" style="height: 40px; background: #fff; padding: 2px;"></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted text-uppercase mb-1">Modo de Comprobante</label>
                                        <select name="ticket_modo" class="form-select shadow-sm fw-bold">
                                            <option value="afip" <?php echo ($conf['ticket_modo']=='afip')?'selected':''; ?>>Factura Electrónica (AFIP)</option>
                                            <option value="interno" <?php echo ($conf['ticket_modo']=='interno')?'selected':''; ?>>Ticket Interno (No Fiscal)</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white py-3 border-0 fw-bold text-success"><i class="bi bi-wallet2 me-2"></i>Cobros e Integraciones</div>
                            <div class="card-body bg-light rounded-bottom">
                                <div class="row g-3">
                                    <div class="col-12">
                                        <div class="form-check form-switch bg-white p-3 rounded-3 border shadow-sm">
                                            <input class="form-check-input ms-1" type="checkbox" name="redondeo_auto" <?php echo $conf['redondeo_auto']?'checked':''; ?>>
                                            <label class="form-check-label fw-bold ms-3">Aplicar Redondeo automático en ventas (Elimina centavos)</label>
                                        </div>
                                    </div>
                                    
                                    <div class="col-12 mt-4">
                                        <label class="small fw-bold text-muted text-uppercase mb-2"><i class="bi bi-bank"></i> Recepción de Transferencias</label>
                                        <select name="metodo_transferencia" class="form-select shadow-sm border-primary fw-bold text-primary">
                                            <option value="manual" <?php echo (!isset($conf['metodo_transferencia']) || $conf['metodo_transferencia']=='manual')?'selected':''; ?>>👁️ Validación Manual (Confirmo en mi App Bancaria)</option>
                                            <option value="ocr" <?php echo ($conf['metodo_transferencia']=='ocr')?'selected':''; ?>>📷 Escáner Inteligente (Saca foto al comprobante del cliente)</option>
                                        </select>
                                    </div>

                                    <div class="col-12 mt-4">
                                        <label class="small fw-bold text-muted text-uppercase mb-2"><i class="bi bi-qr-code"></i> Mercado Pago (QR Dinámico)</label>
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <input type="text" name="mp_access_token" class="form-control shadow-sm" value="<?php echo $conf['mp_access_token']; ?>" placeholder="Access Token (APP_USR-...)">
                                            </div>
                                            <div class="col-md-3">
                                                <input type="text" name="mp_user_id" class="form-control shadow-sm" value="<?php echo $conf['mp_user_id']; ?>" placeholder="User ID">
                                            </div>
                                            <div class="col-md-3">
                                                <input type="text" name="mp_pos_id" class="form-control shadow-sm" value="<?php echo $conf['mp_pos_id']; ?>" placeholder="ID Caja">
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="card border-0 shadow-sm rounded-4 mb-4">
                            <div class="card-header bg-white py-3 border-0 fw-bold text-secondary"><i class="bi bi-sliders me-2"></i>Ajustes Avanzados (Módulos y Alertas)</div>
                            <div class="card-body bg-light rounded-bottom">
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted text-uppercase mb-1">Días Alerta Vencimiento</label>
                                        <input type="number" name="dias_alerta_vencimiento" class="form-control shadow-sm" value="<?php echo $conf['dias_alerta_vencimiento']; ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted text-uppercase mb-1">Valor del Punto Fidelidad ($)</label>
                                        <input type="number" step="0.01" name="dinero_por_punto" class="form-control shadow-sm" value="<?php echo $conf['dinero_por_punto']; ?>">
                                    </div>
                                    <div class="col-12 mt-3">
                                        <div class="bg-white p-3 rounded-3 border shadow-sm">
                                            <label class="small fw-bold text-muted text-uppercase mb-2 d-block border-bottom pb-2">Alerta de Stock Global</label>
                                            <div class="form-check form-switch mb-2">
                                                <input class="form-check-input" type="checkbox" name="stock_use_global" <?php echo $conf['stock_use_global']?'checked':''; ?>>
                                                <label class="small ms-2 fw-bold text-dark">Notificar cuando cualquier producto baje de:</label>
                                            </div>
                                            <div class="input-group input-group-sm w-50">
                                                <input type="number" name="stock_global_valor" class="form-control" value="<?php echo $conf['stock_global_valor']; ?>">
                                                <span class="input-group-text bg-light">unidades</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-12 mt-3">
                                        <label class="small fw-bold text-muted text-uppercase mb-2 d-block">Módulos Visibles en el Menú</label>
                                        <div class="d-flex flex-wrap gap-4 bg-white p-3 rounded-3 border shadow-sm">
                                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="modulo_stock" <?php echo $conf['modulo_stock']?'checked':''; ?>><label class="small ms-1 fw-bold">Stock</label></div>
                                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="modulo_clientes" <?php echo $conf['modulo_clientes']?'checked':''; ?>><label class="small ms-1 fw-bold">Clientes</label></div>
                                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="modulo_reportes" <?php echo $conf['modulo_reportes']?'checked':''; ?>><label class="small ms-1 fw-bold">Reportes</label></div>
                                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="modulo_fidelizacion" <?php echo $conf['modulo_fidelizacion']?'checked':''; ?>><label class="small ms-1 fw-bold">Fidelización</label></div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 text-center text-md-end" style="position: sticky; bottom: 20px; z-index: 100;">
                            <button type="submit" class="btn btn-primary btn-lg fw-bold shadow-lg rounded-pill px-5 py-3 border border-2 border-white">
                                <i class="bi bi-save-fill me-2"></i> GUARDAR GENERAL
                            </button>
                        </div>
                    </form>
                </div>

                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm rounded-4 p-4 text-center mb-4">
                        <i class="bi bi-cloud-upload text-primary fs-1 mb-2"></i>
                        <h5 class="fw-bold">Importación Masiva</h5>
                        <p class="text-muted small">Carga tus productos y precios desde un archivo Excel/CSV rápidamente.</p>
                        <a href="importador_maestro.php" class="btn btn-outline-primary rounded-pill fw-bold shadow-sm">IR AL IMPORTADOR</a>
                    </div>
                    <div class="card border-0 shadow-sm rounded-4 p-4 text-center bg-dark text-white">
                        <i class="bi bi-database-down text-warning fs-1 mb-2"></i>
                        <h5 class="fw-bold">Respaldo de Datos</h5>
                        <p class="text-light small opacity-75">Descargá una copia de seguridad de toda tu base de datos (.SQL).</p>
                        <a href="generar_backup.php" class="btn btn-warning text-dark rounded-pill fw-bold shadow-sm">DESCARGAR BACKUP</a>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="afip">
            <div class="row justify-content-center">
                <div class="col-md-8">
                    <div class="card border-0 shadow-sm rounded-4">
                        <div class="card-header bg-white py-3 border-0 fw-bold text-danger"><i class="bi bi-receipt me-2"></i>Facturación Electrónica</div>
                        <div class="card-body bg-light rounded-bottom p-4">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                                <input type="hidden" name="guardar_afip" value="1">
                                
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted text-uppercase mb-1">CUIT Titular (Sin guiones)</label>
                                        <input type="text" name="cuit_afip" class="form-control shadow-sm fw-bold text-dark" value="<?php echo $afip['cuit']; ?>" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted text-uppercase mb-1">Punto de Venta Oficial</label>
                                        <input type="number" name="punto_venta" class="form-control shadow-sm fw-bold text-dark" value="<?php echo $afip['punto_venta']; ?>" required>
                                    </div>
                                    <div class="col-12 mt-4">
                                        <label class="small fw-bold text-muted text-uppercase mb-1">Entorno de Conexión</label>
                                        <select name="modo_afip" class="form-select shadow-sm fw-bold text-dark">
                                            <option value="homologacion" <?php echo ($afip['modo']=='homologacion')?'selected':''; ?>>🛠️ MODO PRUEBAS (Homologación)</option>
                                            <option value="produccion" <?php echo ($afip['modo']=='produccion')?'selected':''; ?>>✅ MODO REAL (Producción)</option>
                                        </select>
                                    </div>
                                    <div class="col-12"><hr class="my-4"></div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted text-uppercase mb-1">Certificado (.crt)</label>
                                        <input type="file" name="cert_crt" class="form-control shadow-sm bg-white" accept=".crt">
                                    </div>
                                    <div class="col-md-6">
                                        <label class="small fw-bold text-muted text-uppercase mb-1">Clave Privada (.key)</label>
                                        <input type="file" name="cert_key" class="form-control shadow-sm bg-white" accept=".key">
                                    </div>
                                </div>

                                <div class="mt-4 pt-3 text-end">
                                    <button type="submit" name="guardar_afip" class="btn btn-danger fw-bold shadow rounded-pill px-5 py-2">ACTUALIZAR AFIP</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="tab-pane fade" id="personalizacion">
            
            <div class="card border-0 shadow-sm rounded-4 mb-4" style="border-left: 5px solid #0d6efd !important;">
                <div class="card-body p-4 bg-white rounded-4">
                    <form action="cambiar_rubro.php" method="POST" class="d-flex align-items-end gap-3 mb-0">
                        <div class="flex-grow-1">
                            <label class="small fw-bold text-primary text-uppercase mb-2"><i class="bi bi-magic me-1"></i> Aplicar Tema Rápido (Marca Blanca)</label>
                            <select name="rubro_seleccionado" class="form-select shadow-sm fw-bold border-primary text-dark">
                                <option value="kiosco" <?php echo (($conf['tipo_negocio'] ?? '') == 'kiosco') ? 'selected' : ''; ?>>🏪 Kiosco / Minimarket</option>
                                <option value="ferreteria" <?php echo (($conf['tipo_negocio'] ?? '') == 'ferreteria') ? 'selected' : ''; ?>>🔧 Ferretería / Corralón</option>
                                <option value="dietetica" <?php echo (($conf['tipo_negocio'] ?? '') == 'dietetica') ? 'selected' : ''; ?>>🌿 Dietética / Todo Suelto</option>
                                <option value="libreria" <?php echo (($conf['tipo_negocio'] ?? '') == 'libreria') ? 'selected' : ''; ?>>📚 Librería / Papelería</option>
                                <option value="petshop" <?php echo (($conf['tipo_negocio'] ?? '') == 'petshop') ? 'selected' : ''; ?>>🐕 Pet Shop / Veterinaria</option>
                                <option value="indumentaria" <?php echo (($conf['tipo_negocio'] ?? '') == 'indumentaria') ? 'selected' : ''; ?>>👗 Indumentaria / Ropa</option>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary shadow fw-bold px-4">APLICAR TEMA</button>
                    </form>
                </div>
            </div>
            <form method="POST">
                <input type="hidden" name="guardar_personalizacion" value="1">
                
                <div class="row g-4">
                    <div class="col-12">
                        <div class="card border-0 shadow-sm rounded-4">
                            <div class="card-header bg-white py-3 border-0 fw-bold text-success"><i class="bi bi-palette me-2"></i>Mística y Colores</div>
                            <div class="card-body bg-light p-4 rounded-bottom">
                                
                                <div class="mb-4 bg-white p-3 rounded-3 border shadow-sm" style="max-width: 400px;">
                                    <label class="small fw-bold text-muted text-uppercase mb-2">Color Corporativo del Sistema</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color border-0 p-1" id="colorPicker" value="<?php echo $color_sistema; ?>" style="width: 50px; flex: none;">
                                        <input type="text" name="color_principal" id="colorHex" class="form-control fw-bold text-dark border-start-0" value="<?php echo $color_sistema; ?>" maxlength="7">
                                    </div>
                                </div>

                                <h6 class="fw-bold mb-3 border-bottom pb-2">Secciones Principales y Punto de Venta</h6>
                                <div class="row g-3 mb-4">
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Caja 1</label><input type="text" name="label_seccion_1" class="form-control shadow-sm" value="<?php echo $conf['label_seccion_1']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Caja 2</label><input type="text" name="label_seccion_2" class="form-control shadow-sm" value="<?php echo $conf['label_seccion_2']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Caja 3</label><input type="text" name="label_seccion_3" class="form-control shadow-sm" value="<?php echo $conf['label_seccion_3']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1 text-primary">Terminal POS</label><input type="text" name="label_punto_venta" class="form-control shadow-sm border-primary" value="<?php echo $conf['label_punto_venta']; ?>"></div>
                                </div>

                                <h6 class="fw-bold mb-3 border-bottom pb-2">Nombres de Módulos (Botones)</h6>
                                <div class="row g-3">
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Productos</label><input type="text" name="label_productos" class="form-control shadow-sm" value="<?php echo $conf['label_productos']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Combos</label><input type="text" name="label_combos" class="form-control shadow-sm" value="<?php echo $conf['label_combos']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Clientes</label><input type="text" name="label_clientes" class="form-control shadow-sm" value="<?php echo $conf['label_clientes']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Proveedores</label><input type="text" name="label_proveedores" class="form-control shadow-sm" value="<?php echo $conf['label_proveedores']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Sorteos</label><input type="text" name="label_sorteos" class="form-control shadow-sm" value="<?php echo $conf['label_sorteos']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Recaudación</label><input type="text" name="label_recaudacion" class="form-control shadow-sm" value="<?php echo $conf['label_recaudacion']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Gastos</label><input type="text" name="label_gastos" class="form-control shadow-sm" value="<?php echo $conf['label_gastos']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Aumentos</label><input type="text" name="label_aumentos" class="form-control shadow-sm" value="<?php echo $conf['label_aumentos']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Cupones</label><input type="text" name="label_cupones" class="form-control shadow-sm" value="<?php echo $conf['label_cupones']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Revista</label><input type="text" name="label_revista" class="form-control shadow-sm" value="<?php echo $conf['label_revista']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Reportes</label><input type="text" name="label_reportes" class="form-control shadow-sm" value="<?php echo $conf['label_reportes']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Configuración</label><input type="text" name="label_config" class="form-control shadow-sm" value="<?php echo $conf['label_config']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Usuarios</label><input type="text" name="label_usuarios" class="form-control shadow-sm" value="<?php echo $conf['label_usuarios']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Auditoría</label><input type="text" name="label_auditoria" class="form-control shadow-sm" value="<?php echo $conf['label_auditoria']; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Importador</label><input type="text" name="label_importador" class="form-control shadow-sm" value="<?php echo $conf['label_importador'] ; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Categorías</label><input type="text" name="label_categorias" class="form-control shadow-sm" value="<?php echo $conf['label_categorias'] ?? 'RUBROS'; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Mermas</label><input type="text" name="label_mermas" class="form-control shadow-sm" value="<?php echo $conf['label_mermas'] ?? 'BAJAS STOCK'; ?>"></div>
                                    <div class="col-md-3"><label class="small fw-bold text-muted text-uppercase mb-1">Respaldo</label><input type="text" name="label_respaldo" class="form-control shadow-sm" value="<?php echo $conf['label_respaldo'] ?? 'SEGURIDAD'; ?>"></div>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 text-center text-md-end" style="position: sticky; bottom: 20px; z-index: 100;">
                            <button type="submit" class="btn btn-success btn-lg fw-bold shadow-lg rounded-pill px-5 py-3 border border-2 border-white">
                                <i class="bi bi-save-fill me-2"></i> GUARDAR DISEÑO
                            </button>
                        </div>
                    </div>
                </div>
            </form>
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