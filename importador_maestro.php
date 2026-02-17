<?php
// importador_maestro.php - VERSIÓN FINAL CON DISEÑO INTEGRADO
// 1. Activar errores para depuración
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

// 2. Conexión a la Base de Datos
$rutas_db = [__DIR__ . '/db.php', __DIR__ . '/includes/db.php', 'db.php', 'includes/db.php'];
foreach ($rutas_db as $ruta) { if (file_exists($ruta)) { require_once $ruta; break; } }

// 3. Seguridad
if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }

$mensaje = "";
$tipo_mensaje = "";

// 4. Procesar el CSV
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['archivo_csv'])) {
    
    if ($_FILES['archivo_csv']['error'] !== UPLOAD_ERR_OK) {
        $mensaje = "Error al subir el archivo. Código de error: " . $_FILES['archivo_csv']['error'];
        $tipo_mensaje = "danger";
    } else {
        $tmp_name = $_FILES['archivo_csv']['tmp_name'];
        
        // Detectar separador (Coma o Punto y Coma)
        $handle_check = fopen($tmp_name, "r");
        $linea1 = fgets($handle_check);
        fclose($handle_check);
        $separador = (strpos($linea1, ';') !== false) ? ';' : ',';

        $handle = fopen($tmp_name, "r");
        
        if ($handle) {
            $fila = 0; $procesados = 0; $actualizados = 0;

            try {
                // Pre-cargar Categorías y Proveedores
                $catsMap = [];
                $stmtC = $conexion->query("SELECT id, LOWER(nombre) as nombre FROM categorias");
                while($row = $stmtC->fetch(PDO::FETCH_ASSOC)) { $catsMap[$row['nombre']] = $row['id']; }

                $provsMap = [];
                $stmtP = $conexion->query("SELECT id, LOWER(empresa) as empresa FROM proveedores");
                while($row = $stmtP->fetch(PDO::FETCH_ASSOC)) { $provsMap[$row['empresa']] = $row['id']; }

                $conexion->beginTransaction();

                // QUERY ACTUALIZADA CON TODOS LOS CAMPOS
                $sql = "INSERT INTO productos 
                        (codigo_barras, descripcion, id_categoria, id_proveedor, tipo, 
                         precio_costo, precio_venta, precio_oferta, stock_actual, stock_minimo, 
                         activo, fecha_vencimiento, dias_alerta, es_vegano, es_celiaco, es_apto_celiaco, es_apto_vegano) 
                        VALUES 
                        (:cod, :desc, :cat, :prov, :tipo, 
                         :costo, :venta, :oferta, :stock, :min, 
                         1, :venc, :alert, :veg, :cel, :cel, :veg)
                        ON DUPLICATE KEY UPDATE 
                        descripcion = VALUES(descripcion),
                        precio_costo = VALUES(precio_costo),
                        precio_venta = VALUES(precio_venta),
                        precio_oferta = VALUES(precio_oferta),
                        stock_actual = VALUES(stock_actual), 
                        stock_minimo = VALUES(stock_minimo),
                        id_categoria = VALUES(id_categoria),
                        id_proveedor = VALUES(id_proveedor),
                        tipo = VALUES(tipo),
                        fecha_vencimiento = VALUES(fecha_vencimiento),
                        dias_alerta = VALUES(dias_alerta),
                        es_vegano = VALUES(es_vegano),
                        es_celiaco = VALUES(es_celiaco),
                        es_apto_celiaco = VALUES(es_celiaco),
                        es_apto_vegano = VALUES(es_vegano),
                        activo = 1";
                
                $stmt = $conexion->prepare($sql);

                while (($data = fgetcsv($handle, 10000, $separador)) !== FALSE) {
                    $fila++;
                    if ($fila == 1) continue; // Saltar encabezados

                    if (empty($data[0]) || empty($data[1])) { continue; }

                    // --- LIMPIEZA DE DATOS ---
                    $codigo = trim($data[0]);
                    $descripcion = trim($data[1]);
                    
                    // Categoría
                    $catNombre = strtolower(trim($data[2] ?? ''));
                    $idCat = isset($catsMap[$catNombre]) ? $catsMap[$catNombre] : NULL;

                    // Proveedor
                    $provNombre = strtolower(trim($data[3] ?? ''));
                    $idProv = isset($provsMap[$provNombre]) ? $provsMap[$provNombre] : NULL;

                    // Tipo
                    $tipoTxt = strtolower(trim($data[4] ?? ''));
                    $tipoDB = 'unitario';
                    if (strpos($tipoTxt, 'pesable') !== false) $tipoDB = 'pesable';

                    // Valores Numéricos
                    $costo = limpiarNumero($data[5] ?? 0);
                    $venta = limpiarNumero($data[6] ?? 0);
                    $stock = limpiarNumero($data[7] ?? 0);
                    $minimo = limpiarNumero($data[8] ?? 5);
                    if ($minimo <= 0) $minimo = 5;

                    // NUEVOS CAMPOS
                    $oferta = !empty($data[9]) ? limpiarNumero($data[9]) : NULL;
                    
                    // Vencimiento
                    $vencimiento = NULL;
                    if (!empty($data[10])) {
                        $vencRaw = trim($data[10]);
                        if(strpos($vencRaw, '/') !== false) {
                            $parts = explode('/', $vencRaw);
                            if(count($parts)==3) $vencimiento = $parts[2].'-'.$parts[1].'-'.$parts[0];
                        } else {
                            $vencimiento = $vencRaw; 
                        }
                    }

                    $diasAlerta = !empty($data[11]) ? (int)$data[11] : 30;
                    $esVegano = (!empty($data[12]) && (strtoupper($data[12])=='SI' || $data[12]=='1')) ? 1 : 0;
                    $esCeliaco = (!empty($data[13]) && (strtoupper($data[13])=='SI' || $data[13]=='1')) ? 1 : 0;

                    // Ejecutar
                    $stmt->execute([
                        ':cod' => $codigo, ':desc' => $descripcion, ':cat' => $idCat, ':prov' => $idProv,
                        ':tipo' => $tipoDB, ':costo' => $costo, ':venta' => $venta, ':oferta' => $oferta,
                        ':stock' => $stock, ':min' => $minimo, ':venc' => $vencimiento, ':alert' => $diasAlerta,
                        ':veg' => $esVegano, ':cel' => $esCeliaco
                    ]);

                    if ($stmt->rowCount() == 1) $procesados++; 
                    if ($stmt->rowCount() == 2) $actualizados++; 
                }

                $conexion->commit();
                $mensaje = "Se crearon <b>$procesados</b> productos y se actualizaron <b>$actualizados</b>.";
                $tipo_mensaje = "success";

            } catch (Exception $e) {
                $conexion->rollBack();
                $mensaje = "Error: " . $e->getMessage();
                $tipo_mensaje = "danger";
            }
            fclose($handle);
        } else {
            $mensaje = "No se pudo abrir el CSV.";
            $tipo_mensaje = "danger";
        }
    }
}

function limpiarNumero($str) {
    if (empty($str)) return 0;
    $str = str_replace(['$', ' '], '', $str);
    if (strpos($str, '.') !== false && strpos($str, ',') !== false) {
        $str = str_replace('.', '', $str); $str = str_replace(',', '.', $str); 
    } elseif (strpos($str, ',') !== false) {
        $str = str_replace(',', '.', $str); 
    }
    return floatval($str);
}
?>

<?php include 'includes/layout_header.php'; ?>

<style>
    .header-blue { background-color: #102A57; color: white; padding: 40px 0; border-radius: 0 0 30px 30px; position: relative; overflow: hidden; margin-bottom: 25px; }
    .bg-icon-large { position: absolute; top: 50%; right: 20px; transform: translateY(-50%) rotate(-10deg); font-size: 10rem; opacity: 0.1; color: white; pointer-events: none; }
</style>

<div class="header-blue">
    <i class="bi bi-file-earmark-spreadsheet-fill bg-icon-large"></i>
    <div class="container position-relative">
        <h2 class="fw-bold mb-2">Importador Masivo</h2>
        <p class="opacity-75 mb-0">Actualiza tu stock o crea productos desde Excel en segundos.</p>
    </div>
</div>

<div class="container pb-5">
    <div class="row justify-content-center">
        <div class="col-md-10">
            <div class="card shadow border-0 rounded-4">
                <div class="card-body p-5">
                    
                    <?php if($mensaje): ?>
                        <div class="alert alert-<?php echo $tipo_mensaje; ?> alert-dismissible fade show">
                            <?php echo $mensaje; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <div class="alert alert-light border shadow-sm rounded-3 mb-4">
                        <h6 class="fw-bold text-dark"><i class="bi bi-info-circle text-primary"></i> Estructura del Archivo CSV</h6>
                        <p class="small text-muted mb-2">Orden exacto de columnas requeridas:</p>
                        <div class="table-responsive">
                            <table class="table table-bordered table-sm small mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>CODIGO</th><th>DESCRIPCION</th><th>CATEGORIA</th><th>PROVEEDOR</th>
                                        <th>TIPO</th><th>COSTO</th><th>VENTA</th><th>STOCK</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>779123...</td><td>Coca Cola</td><td>Bebidas</td><td>Coca Oficial</td>
                                        <td>Unitario</td><td>1000</td><td>1500</td><td>50</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <form method="POST" enctype="multipart/form-data" class="text-center p-4 border rounded-3 bg-light">
                        <div class="mb-4">
                            <i class="bi bi-cloud-upload display-1 text-primary opacity-50"></i>
                            <br><br>
                            <label class="form-label fw-bold">Seleccionar Archivo CSV</label>
                            <input type="file" name="archivo_csv" class="form-control form-control-lg mx-auto" style="max-width: 500px;" accept=".csv" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-lg fw-bold rounded-pill px-5 shadow">
                            PROCESAR IMPORTACIÓN
                        </button>
                    </form>

                </div>
                <div class="card-footer text-center bg-white border-0 py-3">
                    <a href="configuracion.php" class="btn btn-outline-secondary fw-bold rounded-pill px-4">
                        <i class="bi bi-arrow-left me-2"></i> Volver a Configuración
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'includes/layout_footer.php'; ?>
