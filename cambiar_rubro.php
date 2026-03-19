<?php
session_start();

// 1. BUSCADOR DE CONEXIÓN EXACTO (Copiado de tu configuracion.php para evitar Error 500)
$rutas_db = [__DIR__ . '/db.php', __DIR__ . '/includes/db.php', 'db.php', 'includes/db.php'];
$conexion_ok = false;
foreach ($rutas_db as $ruta) { 
    if (file_exists($ruta)) { require_once $ruta; $conexion_ok = true; break; } 
}

if (!$conexion_ok) {
    die("<h1>Error fatal</h1><p>No se encontró el archivo de conexión db.php.</p>");
}

try {
    // 2. PARCHE AUTOMÁTICO DE BASE DE DATOS (Evita el Error 500 si la columna no existe)
    try {
        $conexion->query("ALTER TABLE configuracion ADD COLUMN tipo_negocio VARCHAR(50) DEFAULT 'kiosco'");
    } catch (Exception $e) { 
        // Si la columna ya existe en la base de datos, ignora el error y continúa.
    }

    $rubro = $_POST['rubro_seleccionado'] ?? 'kiosco';

    // 3. DICCIONARIO DE RUBROS (Contiene 6 rubros completos, podés sumar más luego)
    $presets = [
        'kiosco' => [
            'color' => '#122E5D', // Azul oscuro
            'l_sec_1' => 'GESTIÓN COMERCIAL', 'l_sec_2' => 'FINANZAS', 'l_sec_3' => 'ADMINISTRACIÓN',
            'l_pos' => 'Terminal de Caja', 'l_prod' => 'Inventario', 'l_combos' => 'Combos y Promos',
            'l_cli' => 'Clientes', 'l_prov' => 'Proveedores', 'l_sort' => 'Sorteos',
            'l_gastos' => 'Gastos', 'l_aum' => 'Aumentos', 'l_cup' => 'Cupones',
            'l_rev' => 'Catálogo Web', 'l_rec' => 'Recaudación', 'l_rep' => 'Reportes',
            'l_conf' => 'Configuración', 'l_usu' => 'Usuarios', 'l_aud' => 'Auditoría',
            'l_imp' => 'Importar Excel', 'l_cat' => 'Categorías', 'l_mer' => 'Mermas', 'l_res' => 'Backups'
        ],
        'ferreteria' => [
            'color' => '#c2410c', // Naranja
            'l_sec_1' => 'MOSTRADOR', 'l_sec_2' => 'CAJA Y FINANZAS', 'l_sec_3' => 'TALLER',
            'l_pos' => 'Facturación', 'l_prod' => 'Herramientas y Materiales', 'l_combos' => 'Kits Armados',
            'l_cli' => 'Cuentas Corrientes', 'l_prov' => 'Distribuidores', 'l_sort' => 'Sorteos',
            'l_gastos' => 'Gastos Operativos', 'l_aum' => 'Actualización de Listas', 'l_cup' => 'Descuentos',
            'l_rev' => 'Catálogo de Herramientas', 'l_rec' => 'Cierre de Caja', 'l_rep' => 'Estadísticas',
            'l_conf' => 'Ajustes', 'l_usu' => 'Empleados', 'l_aud' => 'Registro de Acciones',
            'l_imp' => 'Cargar Listas (CSV)', 'l_cat' => 'Rubros', 'l_mer' => 'Roturas/Fallas', 'l_res' => 'Respaldos'
        ],
        'dietetica' => [
            'color' => '#16a34a', // Verde natural
            'l_sec_1' => 'ATENCIÓN', 'l_sec_2' => 'ADMINISTRACIÓN', 'l_sec_3' => 'SISTEMA',
            'l_pos' => 'Balanza y Caja', 'l_prod' => 'Productos y Sueltos', 'l_combos' => 'Mix y Packs',
            'l_cli' => 'Clientes Frecuentes', 'l_prov' => 'Mayoristas', 'l_sort' => 'Sorteos',
            'l_gastos' => 'Salidas', 'l_aum' => 'Cambio de Precios', 'l_cup' => 'Beneficios',
            'l_rev' => 'Catálogo Natural', 'l_rec' => 'Caja Diaria', 'l_rep' => 'Métricas',
            'l_conf' => 'Ajustes Generales', 'l_usu' => 'Personal', 'l_aud' => 'Movimientos',
            'l_imp' => 'Importar Precios', 'l_cat' => 'Familias', 'l_mer' => 'Vencimientos', 'l_res' => 'Backups'
        ],
        'libreria' => [
            'color' => '#0284c7', // Azul claro
            'l_sec_1' => 'MOSTRADOR', 'l_sec_2' => 'ADMINISTRACIÓN', 'l_sec_3' => 'SISTEMA',
            'l_pos' => 'Caja y Cobros', 'l_prod' => 'Artículos Escolares', 'l_combos' => 'Listas y Combos',
            'l_cli' => 'Clientes', 'l_prov' => 'Editoriales', 'l_sort' => 'Sorteos',
            'l_gastos' => 'Gastos', 'l_aum' => 'Aumentos', 'l_cup' => 'Cupones',
            'l_rev' => 'Catálogo Escolar', 'l_rec' => 'Recaudación', 'l_rep' => 'Reportes',
            'l_conf' => 'Configuración', 'l_usu' => 'Vendedores', 'l_aud' => 'Auditoría',
            'l_imp' => 'Importar Listas', 'l_cat' => 'Categorías', 'l_mer' => 'Artículos Dañados', 'l_res' => 'Backups'
        ],
        'petshop' => [
            'color' => '#9333ea', // Violeta
            'l_sec_1' => 'VENTAS Y SERVICIOS', 'l_sec_2' => 'CONTROL Y FINANZAS', 'l_sec_3' => 'ADMINISTRACIÓN',
            'l_pos' => 'Facturación', 'l_prod' => 'Alimentos y Accesorios', 'l_combos' => 'Kits Mascotas',
            'l_cli' => 'Dueños', 'l_prov' => 'Proveedores', 'l_sort' => 'Sorteos',
            'l_gastos' => 'Gastos Locales', 'l_aum' => 'Aumentos de Alimento', 'l_cup' => 'Beneficios',
            'l_rev' => 'Catálogo de Mascotas', 'l_rec' => 'Cierre de Caja', 'l_rep' => 'Estadísticas',
            'l_conf' => 'Ajustes', 'l_usu' => 'Personal', 'l_aud' => 'Auditoría',
            'l_imp' => 'Cargar Listas', 'l_cat' => 'Animales', 'l_mer' => 'Bolsas Rotas', 'l_res' => 'Seguridad'
        ],
        'indumentaria' => [
            'color' => '#be185d', // Rosa oscuro
            'l_sec_1' => 'ATENCIÓN AL CLIENTE', 'l_sec_2' => 'GESTIÓN Y CONTROL', 'l_sec_3' => 'SISTEMA',
            'l_pos' => 'Caja / Cambios', 'l_prod' => 'Prendas y Talles', 'l_combos' => 'Outfits y Promos',
            'l_cli' => 'Clientas VIP', 'l_prov' => 'Talleres / Marcas', 'l_sort' => 'Giveaways',
            'l_gastos' => 'Egresos', 'l_aum' => 'Actualizar Temporada', 'l_cup' => 'Descuentos',
            'l_rev' => 'Lookbook / Catálogo', 'l_rec' => 'Ingresos del Día', 'l_rep' => 'Reportes de Ventas',
            'l_conf' => 'Configuración', 'l_usu' => 'Vendedoras', 'l_aud' => 'Auditoría',
            'l_imp' => 'Importar Stock', 'l_cat' => 'Temporadas', 'l_mer' => 'Prendas Falladas', 'l_res' => 'Respaldo'
        ]
    ];

    if (isset($presets[$rubro])) {
        $p = $presets[$rubro];
        
        $sql = "UPDATE configuracion SET 
                tipo_negocio = :rubro, color_barra_nav = :color,
                label_seccion_1 = :ls1, label_seccion_2 = :ls2, label_seccion_3 = :ls3,
                label_punto_venta = :lpos, label_productos = :lprod, label_combos = :lcom,
                label_clientes = :lcli, label_proveedores = :lprov, label_sorteos = :lsort,
                label_gastos = :lgas, label_aumentos = :laum, label_cupones = :lcup,
                label_revista = :lrev, label_recaudacion = :lrec, label_reportes = :lrep,
                label_config = :lconf, label_usuarios = :lusu, label_auditoria = :laud,
                label_importador = :limp, label_categorias = :lcat, label_mermas = :lmer,
                label_respaldo = :lres
                WHERE id = 1";
                
        $stmt = $conexion->prepare($sql);
        $stmt->execute([
            ':rubro' => $rubro, ':color' => $p['color'],
            ':ls1' => $p['l_sec_1'], ':ls2' => $p['l_sec_2'], ':ls3' => $p['l_sec_3'],
            ':lpos' => $p['l_pos'], ':lprod' => $p['l_prod'], ':lcom' => $p['l_combos'],
            ':lcli' => $p['l_cli'], ':lprov' => $p['l_prov'], ':lsort' => $p['l_sort'],
            ':lgas' => $p['l_gastos'], ':laum' => $p['l_aum'], ':lcup' => $p['l_cup'],
            ':lrev' => $p['l_rev'], ':lrec' => $p['l_rec'], ':lrep' => $p['l_rep'],
            ':lconf' => $p['l_conf'], ':lusu' => $p['l_usu'], ':laud' => $p['l_aud'],
            ':limp' => $p['l_imp'], ':lcat' => $p['l_cat'], ':lmer' => $p['l_mer'],
            ':lres' => $p['l_res']
        ]);

        // Sincronizamos la sesión para que el color de la barra cambie inmediatamente
        $_SESSION['color_barra_nav'] = $p['color'];
        
        // Redirección exitosa
        header("Location: configuracion.php?msg=guardado");
        exit;
    } else {
        die("<h1>Error</h1><p>El rubro seleccionado no existe en los presets.</p><a href='configuracion.php'>Volver</a>");
    }

} catch (Exception $e) {
    // ESTO EVITA EL ERROR 500 y te muestra exactamente en texto si algo falla
    die("<h1>Error interno en la base de datos</h1><p>" . $e->getMessage() . "</p><br><a href='configuracion.php'>Volver a Configuración</a>");
}
?>
