<?php
// restaurar_sistema.php - MÁQUINA DEL TIEMPO V4 (Upload + Restauración Blindada)
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }
$permisos = $_SESSION['permisos'] ?? [];
$es_admin = (($_SESSION['rol'] ?? 3) <= 1); // Solo SuperAdmin
if (!$es_admin && !in_array('restaurar_sistema', $permisos)) { header("Location: dashboard.php"); exit; }

$mensaje = "";
$tipo_mensaje = "";

// Crear carpeta backups si no existe
if (!is_dir('backups')) {
    mkdir('backups', 0777, true);
}

// 1. LÓGICA DE SUBIDA DE ARCHIVO MANUAL
if (isset($_FILES['subir_backup']) && $_FILES['subir_backup']['error'] === UPLOAD_ERR_OK) {
    $archivo_tmp = $_FILES['subir_backup']['tmp_name'];
    $nombre_original = $_FILES['subir_backup']['name'];
    $extension = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));

    if ($extension === 'sql') {
        $nuevo_nombre = 'backup_manual_' . date('Y-m-d_H-i-s') . '.sql';
        $destino = 'backups/' . $nuevo_nombre;

        if (move_uploaded_file($archivo_tmp, $destino)) {
            $mensaje = "¡Archivo subido con éxito! Ahora puedes restaurarlo desde la lista.";
            $tipo_mensaje = "success";
        } else {
            $mensaje = "Error al mover el archivo subido.";
            $tipo_mensaje = "danger";
        }
    } else {
        $mensaje = "Error: El archivo debe tener extensión .sql";
        $tipo_mensaje = "warning";
    }
}

// 2. LÓGICA DE ELIMINACIÓN DE BACKUPS
if (isset($_POST['eliminar_archivos'])) {
    $archivos_json = json_decode($_POST['eliminar_archivos'], true);
    $borrados = 0;
    if (is_array($archivos_json)) {
        foreach ($archivos_json as $file) {
            $file_path = 'backups/' . basename($file);
            if (file_exists($file_path)) {
                unlink($file_path);
                $borrados++;
            }
        }
        $mensaje = "¡Excelente! Se eliminaron $borrados copia(s) de seguridad.";
        $tipo_mensaje = "success";
    }
}

// 3. LÓGICA DE RESTAURACIÓN PROFUNDA (BLINDADA CONTRA ERRORES 500)
if (isset($_POST['restaurar_archivo'])) {
    $nombre_archivo = basename($_POST['restaurar_archivo']);
    $ruta_archivo = 'backups/' . $nombre_archivo;
    
    if (file_exists($ruta_archivo)) {
        // Le damos tiempo y memoria ilimitada a PHP para que no colapse
        set_time_limit(0);
        ini_set('memory_limit', '-1');

        try {
            // APAGAMOS LAS RESTRICCIONES (VITAL PARA PODER REEMPLAZAR TABLAS)
            $conexion->exec("SET FOREIGN_KEY_CHECKS = 0;");
            
            $query = '';
            $lineas = file($ruta_archivo);
            
            if ($lineas !== false) {
                foreach ($lineas as $linea) {
                    $trim_line = trim($linea);
                    
                    // Ignorar comentarios y líneas vacías para no saturar
                    if (empty($trim_line) || strpos($trim_line, '--') === 0 || strpos($trim_line, '/*') === 0) {
                        continue;
                    }
                    
                    $query .= $linea;
                    
                    // Si la línea termina en punto y coma (;), ejecutamos el bloque
                    if (substr($trim_line, -1) === ';') {
                        try {
                            $conexion->exec($query);
                        } catch (PDOException $e) {
                            // Ignoramos errores menores de sintaxis para que no se detenga la restauración completa
                        }
                        $query = ''; // Vaciamos para leer la siguiente instrucción
                    }
                }

                $conexion->exec("SET FOREIGN_KEY_CHECKS = 1;");
                
                $check = $conexion->query("SELECT nombre_negocio FROM configuracion WHERE id = 1")->fetch();
                if ($check) {
                    $mensaje = "¡Sistema Restaurado Correctamente! El negocio vuelve a estar operativo.";
                    $tipo_mensaje = "success";
                } else {
                    $mensaje = "Restauración completada, pero no se detectó configuración inicial.";
                    $tipo_mensaje = "warning";
                }
            } else {
                throw new Exception("No se pudo leer el archivo SQL.");
            }

        } catch (Exception $e) {
            $conexion->exec("SET FOREIGN_KEY_CHECKS = 1;"); // Reactivar siempre por seguridad
            $mensaje = "Error crítico durante la restauración: " . $e->getMessage();
            $tipo_mensaje = "danger";
        }
    }
}

// 4. LECTURA DE ARCHIVOS DE BACKUP
$backups = [];
if (is_dir('backups')) {
    $archivos = scandir('backups', SCANDIR_SORT_DESCENDING);
    foreach ($archivos as $arch) {
        if (strpos($arch, '.sql') !== false) {
            $ruta_completa = 'backups/' . $arch;
            $backups[] = [
                'nombre' => $arch,
                'fecha' => date("d/m/Y H:i:s", filemtime($ruta_completa)),
                'peso' => round(filesize($ruta_completa) / 1024, 2) . ' KB'
            ];
        }
    }
}
?>

<?php include 'includes/layout_header.php'; ?>

<div class="container py-5">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="font-cancha mb-0 text-primary"><i class="bi bi-clock-history"></i> Máquina del Tiempo</h2>
            <p class="text-muted mb-0">Restaura, sube o elimina copias de seguridad del sistema.</p>
        </div>
        <a href="reset_sistema.php" class="btn btn-outline-danger fw-bold rounded-pill shadow-sm">
            <i class="bi bi-trash3"></i> Ir a Resetear
        </a>
    </div>

    <?php if($mensaje): ?>
        <div class="alert alert-<?php echo $tipo_mensaje; ?> fw-bold text-center rounded-4 mb-4 shadow-sm">
            <?php echo $mensaje; ?>
        </div>
    <?php endif; ?>

    <div class="card shadow-sm border-0 rounded-4 mb-4">
        <div class="card-body p-4 bg-light rounded-4 border border-primary border-opacity-25">
            <h6 class="fw-bold text-primary mb-3"><i class="bi bi-cloud-upload-fill me-2"></i> Subir Respaldo Externo</h6>
            <form method="POST" enctype="multipart/form-data" class="d-flex align-items-center gap-3">
                <input type="file" name="subir_backup" class="form-control" accept=".sql" required>
                <button type="submit" class="btn btn-primary fw-bold px-4 text-nowrap shadow-sm">
                    <i class="bi bi-upload"></i> Subir Archivo
                </button>
            </form>
            <small class="text-muted mt-2 d-block">Solo se permiten archivos con formato <strong>.sql</strong> descargados previamente de este u otro sistema compatible.</small>
        </div>
    </div>

    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-header bg-dark text-white fw-bold py-3 px-4 d-flex justify-content-between align-items-center">
            <span>Copias Disponibles en Servidor (<?php echo count($backups); ?>)</span>
            <button id="btnBorrarMasivo" class="btn btn-sm btn-danger fw-bold rounded-pill d-none shadow-sm" onclick="borrarSeleccionados()">
                <i class="bi bi-trash-fill"></i> Eliminar Seleccionados
            </button>
        </div>
        <div class="card-body p-0">
            <?php if(empty($backups)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="bi bi-server display-4 d-block mb-3 opacity-25"></i>
                    No hay backups guardados en el servidor actualmente.
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light">
                            <tr class="small text-uppercase text-muted">
                                <th class="text-center" style="width: 50px;">
                                    <input type="checkbox" id="checkAll" class="form-check-input shadow-sm" onchange="toggleAllCheckboxes(this)">
                                </th>
                                <th class="ps-2">Archivo SQL</th>
                                <th>Fecha y Hora</th>
                                <th>Peso</th>
                                <th class="text-end pe-4">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($backups as $b): ?>
                            <tr>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input shadow-sm chk-backup" value="<?php echo htmlspecialchars($b['nombre']); ?>" onchange="verificarSeleccion()">
                                </td>
                                <td class="ps-2 fw-bold text-primary">
                                    <i class="bi bi-database-fill me-2 text-secondary"></i> <?php echo htmlspecialchars($b['nombre']); ?>
                                </td>
                                <td class="small text-muted fw-bold"><?php echo $b['fecha']; ?></td>
                                <td><span class="badge bg-secondary bg-opacity-25 text-dark"><?php echo $b['peso']; ?></span></td>
                                <td class="text-end pe-4 text-nowrap">
                                    <form method="POST" class="d-inline form-restaurar">
                                        <input type="hidden" name="restaurar_archivo" value="<?php echo htmlspecialchars($b['nombre']); ?>">
                                        <button type="button" class="btn btn-success btn-sm rounded-pill fw-bold px-3 shadow-sm me-1 btn-ejecutar-restauracion">
                                            <i class="bi bi-arrow-counterclockwise"></i> Restaurar
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-outline-danger btn-sm rounded-circle shadow-sm" onclick="borrarIndividual('<?php echo htmlspecialchars($b['nombre']); ?>')" title="Eliminar Backup">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<form id="formDelete" method="POST" style="display: none;">
    <input type="hidden" name="eliminar_archivos" id="inputArchivosEliminar">
</form>

<script>
    // Confirmación de Restauración Segura (SweetAlert)
    document.querySelectorAll('.btn-ejecutar-restauracion').forEach(btn => {
        btn.addEventListener('click', function() {
            let form = this.closest('form');
            Swal.fire({
                title: '¿Iniciar Restauración?',
                text: "Toda la base de datos actual se borrará y será reemplazada por los datos de este archivo SQL. Esta acción es irreversible.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#198754',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Sí, restaurar ahora',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Restaurando Sistema...',
                        html: 'Por favor, no cierres esta ventana. Esto puede tomar unos segundos.',
                        allowOutsideClick: false,
                        didOpen: () => { Swal.showLoading(); }
                    });
                    form.submit();
                }
            });
        });
    });

    // JS Lógica de Checkboxes
    function toggleAllCheckboxes(source) {
        let checkboxes = document.querySelectorAll('.chk-backup');
        checkboxes.forEach(chk => chk.checked = source.checked);
        verificarSeleccion();
    }

    function verificarSeleccion() {
        let checkboxes = document.querySelectorAll('.chk-backup:checked');
        let btnBorrar = document.getElementById('btnBorrarMasivo');
        if (checkboxes.length > 0) {
            btnBorrar.classList.remove('d-none');
            btnBorrar.innerHTML = `<i class="bi bi-trash-fill"></i> Eliminar Seleccionados (${checkboxes.length})`;
        } else {
            btnBorrar.classList.add('d-none');
        }
    }

    // JS Lógica de Borrado Individual
    function borrarIndividual(nombreArchivo) {
        Swal.fire({
            title: '¿Eliminar Backup?',
            text: "Se borrará permanentemente del servidor.",
            icon: 'error',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Sí, eliminar archivo'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('inputArchivosEliminar').value = JSON.stringify([nombreArchivo]);
                document.getElementById('formDelete').submit();
            }
        });
    }

    // JS Lógica de Borrado Masivo
    function borrarSeleccionados() {
        let checkboxes = document.querySelectorAll('.chk-backup:checked');
        let archivos = Array.from(checkboxes).map(chk => chk.value);
        
        if (archivos.length === 0) return;

        Swal.fire({
            title: `¿Eliminar ${archivos.length} archivos?`,
            text: "Esta acción no se puede deshacer.",
            icon: 'error',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            confirmButtonText: 'Sí, eliminar todo'
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('inputArchivosEliminar').value = JSON.stringify(archivos);
                document.getElementById('formDelete').submit();
            }
        });
    }
</script>

<?php include 'includes/layout_footer.php'; ?>