<?php
// acciones/procesar_importacion.php
session_start();
require_once '../includes/db.php';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_FILES['archivo_csv'])) {
    
    $archivo = $_FILES['archivo_csv'];
    $ext = pathinfo($archivo['name'], PATHINFO_EXTENSION);

    if (strtolower($ext) !== 'csv') {
        die("Error: Por favor suba un archivo con extensión .csv");
    }

    // Abrir archivo
    $handle = fopen($archivo['tmp_name'], "r");
    if ($handle === FALSE) {
        die("Error al abrir el archivo.");
    }

    // Contadores
    $nuevos = 0;
    $actualizados = 0;
    $errores = 0;
    $fila = 0;

    try {
        $conexion->beginTransaction();

        // 1. PREPARAR CACHÉ DE CATEGORIAS Y PROVEEDORES (Para no consultar DB en cada fila)
        // Traemos todas las categorías y proveedores a un array para buscar rápido
        $catsDB = $conexion->query("SELECT id, nombre FROM categorias")->fetchAll(PDO::FETCH_KEY_PAIR); // [id => nombre]... no, mejor [nombre => id]
        
        // Invertimos para buscar por nombre
        $mapCategorias = [];
        foreach($conexion->query("SELECT id, LOWER(nombre) as nombre FROM categorias") as $c) {
            $mapCategorias[$c['nombre']] = $c['id'];
        }

        $mapProveedores = [];
        foreach($conexion->query("SELECT id, LOWER(empresa) as empresa FROM proveedores") as $p) {
            $mapProveedores[$p['empresa']] = $p['id'];
        }

        // PREPARAR SENTENCIA SQL (INSERT ... ON DUPLICATE KEY UPDATE)
        // Esto es magia pura: Si el codigo existe, actualiza. Si no, inserta.
        $sql = "INSERT INTO productos 
                (codigo_barras, descripcion, id_categoria, id_proveedor, tipo, precio_costo, precio_venta, stock_actual, stock_minimo, es_apto_celiaco, es_apto_vegano, activo) 
                VALUES 
                (:codigo, :desc, :cat, :prov, :tipo, :costo, :venta, :stock, :min, 0, 0, 1)
                ON DUPLICATE KEY UPDATE 
                descripcion = VALUES(descripcion),
                precio_costo = VALUES(precio_costo),
                precio_venta = VALUES(precio_venta),
                stock_actual = stock_actual + VALUES(stock_actual), -- Sumamos stock si ya existe
                activo = 1"; 
        
        $stmt = $conexion->prepare($sql);

        // LEER FILA POR FILA
        while (($data = fgetcsv($handle, 1000, ";")) !== FALSE) { // Asumimos punto y coma que es común en Excel español
            $fila++;
            if ($fila == 1) continue; // Saltar encabezados

            // Validar que la fila tenga al menos datos básicos
            // Estructura esperada: CODIGO;DESCRIPCION;CATEGORIA;PROVEEDOR;COSTO;VENTA;STOCK;MIN;PESABLE
            if (count($data) < 6) continue; // Fila corrupta o vacía

            $codigo = trim($data[0]);
            $descripcion = trim($data[1]);
            
            if (empty($codigo) || empty($descripcion)) continue;

            // --- 1. PROCESAR CATEGORIA ---
            $catNombre = strtolower(trim($data[2]));
            $idCat = 1; // ID por defecto (asegurate que exista la categoria ID 1, o cambialo)
            if (isset($mapCategorias[$catNombre])) {
                $idCat = $mapCategorias[$catNombre];
            } else {
                // Si quisieras crearla al vuelo, aquí iría el código. 
                // Por seguridad y orden, asignamos a ID 1 o la primera que encontremos.
                 if(!empty($mapCategorias)) {
                     $idCat = reset($mapCategorias); // Asigna la primera del array si no encuentra nombre
                 }
            }

            // --- 2. PROCESAR PROVEEDOR ---
            $provNombre = strtolower(trim($data[3]));
            $idProv = 1; 
            if (isset($mapProveedores[$provNombre])) {
                $idProv = $mapProveedores[$provNombre];
            } else {
                if(!empty($mapProveedores)) {
                     $idProv = reset($mapProveedores);
                 }
            }

            // --- 3. LIMPIEZA DE NÚMEROS (Costos y Precios) ---
            // Función auxiliar interna para limpiar precios (quita $, cambia coma por punto)
            $costo = limpiarNumero($data[4]);
            $venta = limpiarNumero($data[5]);
            $stock = limpiarNumero($data[6]);
            $minimo = !empty($data[7]) ? limpiarNumero($data[7]) : 5;

            // --- 4. TIPO ---
            $esPesable = strtoupper(trim($data[8] ?? 'NO'));
            $tipo = ($esPesable === 'SI') ? 'pesable' : 'unitario';

            // --- 5. EJECUTAR ---
            // Verificamos si es nuevo o update para el contador (opcional, cuesta una query extra)
            // Para ser rápidos, simplemente ejecutamos el upsert
            $stmt->execute([
                ':codigo' => $codigo,
                ':desc' => $descripcion,
                ':cat' => $idCat,
                ':prov' => $idProv,
                ':tipo' => $tipo,
                ':costo' => $costo,
                ':venta' => $venta,
                ':stock' => $stock,
                ':min' => $minimo
            ]);
            
            if ($stmt->rowCount() > 0) {
                // rowCount devuelve 1 si inserta, 2 si actualiza (en MySQL upsert)
                $actualizados++;
            }
        }

        $conexion->commit();
        fclose($handle);

        echo "<script>
            alert('Proceso Terminado.\\nFilas Procesadas: " . ($fila - 1) . "\\n(Nota: Si el producto ya existía, se actualizó)');
            window.location.href = '../productos.php';
        </script>";

    } catch (Exception $e) {
        $conexion->rollBack();
        fclose($handle);
        die("Error Crítico en la Importación: " . $e->getMessage());
    }

} else {
    header("Location: ../importar_productos.php");
}

// Función auxiliar para limpiar moneda (ej: "$ 1.500,00" -> 1500.00)
function limpiarNumero($str) {
    $str = str_replace('$', '', $str); // Quitar signo pesos
    $str = trim($str);
    
    // Si tiene coma y punto, asumimos formato 1.000,00
    if (strpos($str, ',') !== false && strpos($str, '.') !== false) {
        $str = str_replace('.', '', $str); // Quitar punto de miles
        $str = str_replace(',', '.', $str); // Cambiar coma decimal a punto
    } 
    // Si solo tiene coma, asumimos decimal 50,50
    elseif (strpos($str, ',') !== false) {
        $str = str_replace(',', '.', $str);
    }
    
    return floatval($str);
}
?>