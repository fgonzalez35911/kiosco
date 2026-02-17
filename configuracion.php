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
    // VALIDACIÓN QUIRÚRGICA DE SEGURIDAD
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Error de seguridad: Solicitud no autorizada.");
    }
    $nombre = trim($_POST['nombre_negocio']);
    $direccion = trim($_POST['direccion']);
    $telefono = trim($_POST['telefono']);
    $wa_pedidos = trim($_POST['whatsapp_pedidos']);
    $cuit = trim($_POST['cuit']);
    $mensaje = trim($_POST['mensaje_ticket']);
    
    // NUEVO: Color del sistema
    $color_principal = $_POST['color_principal'] ?? '#102A57';

    $mod_cli = isset($_POST['modulo_clientes']) ? 1 : 0;
    $mod_stk = isset($_POST['modulo_stock']) ? 1 : 0;
    $mod_rep = isset($_POST['modulo_reportes']) ? 1 : 0;
    $mod_fid = isset($_POST['modulo_fidelizacion']) ? 1 : 0;
    
    $stock_use_global = isset($_POST['stock_use_global']) ? 1 : 0;
    $stock_global_valor = intval($_POST['stock_global_valor'] ?? 5);
    $ticket_modo = $_POST['ticket_modo'] ?? 'afip';
    $redondeo_auto = isset($_POST['redondeo_auto']) ? 1 : 0;

    $dias_alerta = intval($_POST['dias_alerta_vencimiento'] ?? 30);
    $dinero_punto = floatval($_POST['dinero_por_punto'] ?? 100);

    $logo_url = $_POST['logo_actual']; 
    if (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        $nombre_archivo = 'logo_' . time() . '.png';
        $destino = 'uploads/' . $nombre_archivo;
        if(!is_dir('uploads')) mkdir('uploads');
        if (move_uploaded_file($_FILES['logo']['tmp_name'], $destino)) {
            $logo_url = $destino;
        }
    }

   // ACTUALIZAMOS LA BASE DE DATOS INCLUYENDO EL COLOR
   $sql = "UPDATE configuracion SET 
            nombre_negocio=?, direccion_local=?, telefono_whatsapp=?, whatsapp_pedidos=?, cuit=?, mensaje_ticket=?, 
            modulo_clientes=?, modulo_stock=?, modulo_reportes=?, modulo_fidelizacion=?, logo_url=?,
            dias_alerta_vencimiento=?, dinero_por_punto=?,
            stock_use_global=?, stock_global_valor=?, ticket_modo=?, redondeo_auto=?,
            color_principal=? 
            WHERE id=1";

    $conexion->prepare($sql)->execute([
        $nombre, $direccion, $telefono, $wa_pedidos, $cuit, $mensaje, 
        $mod_cli, $mod_stk, $mod_rep, $mod_fid, $logo_url,
        $dias_alerta, $dinero_punto,
        $stock_use_global, $stock_global_valor, $ticket_modo, $redondeo_auto,
        $color_principal
    ]);
    
    header("Location: configuracion.php?msg=guardado"); exit;
}

// --- 2. PROCESAR GUARDADO AFIP ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['guardar_afip'])) {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Error de seguridad: Solicitud no autorizada.");
    }

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

    $conexion->prepare("UPDATE afip_config SET cuit=?, punto_venta=?, modo=? WHERE id=1")->execute([$cuit_afip, $pto_vta, $modo]);
    $conexion->query("UPDATE afip_config SET token=NULL, sign=NULL WHERE id=1");

    header("Location: configuracion.php?msg=afip_ok"); exit;
}

// 3. OBTENER DATOS
$conf = $conexion->query("SELECT * FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);
$afip = $conexion->query("SELECT * FROM afip_config WHERE id=1")->fetch(PDO::FETCH_ASSOC);

// Color por defecto si no existe en BD
$color_sistema = $conf['color_principal'] ?? '#102A57';

include 'includes/layout_header.php'; ?>

</div> 

<style>
    /* Estilos específicos dinámicos para las pestañas de esta página */
    .nav-tabs .nav-link { color: #6c757d; font-weight: 600; border: none; }
    .nav-tabs .nav-link.active { 
        color: <?php echo $color_sistema; ?>; 
        border-bottom: 3px solid <?php echo $color_sistema; ?>; 
        background: transparent; 
        
    }
    
</style>

<div class="header-blue" style="background-color: <?php echo $color_sistema; ?> !important; background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100%;">
    <i class="bi bi-gear bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Panel de Configuración</h2>
                <p class="opacity-75 mb-0 text-white small">Ajustes generales y facturación electrónica del sistema.</p>
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

<div class="container pb-5">

<div class="container pb-5">
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
                                        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="modulo_fidelizacion" <?php echo $conf['modulo_fidelizacion']?'checked':''; ?>><label class="small ms-2">Puntos</label></div>
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

<?php 
if(isset($_GET['msg'])) {
    $m = $_GET['msg'];
    if($m == 'guardado') echo "<script>Swal.fire('Éxito', 'Configuración guardada', 'success');</script>";
    if($m == 'afip_ok') echo "<script>Swal.fire('AFIP', 'Datos de facturación listos', 'success');</script>";
}
include 'includes/layout_footer.php'; 
?>