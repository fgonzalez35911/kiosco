<?php
// gestionar_premios.php - VERSIÓN PREMIUM UNIFICADA
require_once 'includes/layout_header.php'; 

// El header ya conectó a la DB, inició sesión y cargó el $color_sistema.
$mensaje = '';

// CARGAR PRODUCTOS Y COMBOS PARA EL SELECTOR
$prods_db = $conexion->query("SELECT id, descripcion FROM productos WHERE activo=1 AND tipo != 'combo' ORDER BY descripcion")->fetchAll(PDO::FETCH_ASSOC);
$combos_db = $conexion->query("SELECT id, nombre FROM combos WHERE activo=1 ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);

// 3. AGREGAR PREMIO (LÓGICA CORREGIDA)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['agregar'])) {
    try {
        $nombre = $_POST['nombre'];
        $puntos = $_POST['puntos'];
        $stock = $_POST['stock'];
        $es_cupon = isset($_POST['es_cupon']) && $_POST['es_cupon'] == "1" ? 1 : 0;
        $monto = $_POST['monto_dinero'] ?? 0;
        
        $tipo_articulo = $_POST['tipo_articulo'] ?? 'ninguno';
        $id_articulo = null;

        // Si es cupón, ignoramos productos/combos
        if ($es_cupon == 1) {
            $tipo_articulo = 'ninguno';
            $id_articulo = null;
        } else {
            // Si es mercadería, tomamos el ID según lo que eligió en el desplegable
            if ($tipo_articulo == 'producto') {
                $id_articulo = !empty($_POST['id_articulo_prod']) ? $_POST['id_articulo_prod'] : null;
            } elseif ($tipo_articulo == 'combo') {
                $id_articulo = !empty($_POST['id_articulo_combo']) ? $_POST['id_articulo_combo'] : null;
            }
        }

        // INSERTAR (Solo una vez, limpio)
        $sql = "INSERT INTO premios (nombre, puntos_necesarios, stock, es_cupon, monto_dinero, activo, id_articulo, tipo_articulo) 
                VALUES (?, ?, ?, ?, ?, 1, ?, ?)";
        $conexion->prepare($sql)->execute([$nombre, $puntos, $stock, $es_cupon, $monto, $id_articulo, $tipo_articulo]);
        
        header("Location: gestionar_premios.php?msg=creado"); exit;
    } catch (Exception $e) {
        $mensaje = '<div class="alert alert-danger">Error: '.$e->getMessage().'</div>';
    }
}

// 4. BORRAR
if (isset($_GET['borrar'])) {
    $conexion->prepare("DELETE FROM premios WHERE id = ?")->execute([$_GET['borrar']]);
    header("Location: gestionar_premios.php?msg=borrado"); exit;
}

// 5. LISTAR (Con datos de vinculación)
try {
    $sqlLista = "SELECT p.*, 
                 CASE 
                    WHEN p.tipo_articulo = 'producto' THEN (SELECT descripcion FROM productos WHERE id = p.id_articulo)
                    WHEN p.tipo_articulo = 'combo' THEN (SELECT nombre FROM combos WHERE id = p.id_articulo)
                    ELSE NULL 
                 END as nombre_vinculo
                 FROM premios p 
                 ORDER BY p.puntos_necesarios ASC";
    $lista = $conexion->query($sqlLista)->fetchAll(PDO::FETCH_ASSOC);
    $total_premios = count($lista);
    $fisicos = 0; $cupones = 0;
    foreach($lista as $p) { if($p['es_cupon'] == 1) $cupones++; else $fisicos++; }
} catch (Exception $e) { $lista = []; $total_premios = 0; $fisicos = 0; $cupones = 0; }
?>



    <?php include 'includes/layout_header.php'; ?>
</div> <div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; position: relative; overflow: hidden;">
    <i class="bi bi-gift-fill bg-icon-large"></i>
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="font-cancha mb-0 text-white">Catálogo de Premios</h2>
                <p class="opacity-75 mb-0 text-white small">Gestioná los regalos para el canje de puntos.</p>
            </div>
            <a href="canje_puntos.php" class="btn btn-outline-light rounded-pill fw-bold px-4 shadow-sm">
                <i class="bi bi-arrow-left-circle me-2"></i> VOLVER
            </a>
        </div>

        <div class="row g-3">
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Total Premios</div>
                        <div class="widget-value text-white"><?php echo $total_premios; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-gift"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Mercadería</div>
                        <div class="widget-value text-white"><?php echo $fisicos; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-box-seam"></i></div>
                </div>
            </div>
            <div class="col-12 col-md-4">
                <div class="header-widget">
                    <div>
                        <div class="widget-label">Cupones Dinero</div>
                        <div class="widget-value text-white"><?php echo $cupones; ?></div>
                    </div>
                    <div class="icon-box bg-white bg-opacity-10 text-white"><i class="bi bi-cash-coin"></i></div>
                </div>
            </div>
        </div>
    </div>
</div>

    <div class="container pb-5">
        <div class="row g-4">
            
            <div class="col-md-4">
                <div class="card card-custom h-100">
                    <div class="card-header bg-white fw-bold py-3 border-bottom-0 text-primary">
                        <i class="bi bi-plus-circle-fill me-2"></i> Nuevo Premio
                    </div>
                    <div class="card-body bg-light rounded-bottom">
                        <?php echo $mensaje; ?>
                        
                        <form method="POST">
                            <input type="hidden" name="agregar" value="1">
                            
                            <div class="mb-3">
                                <label class="small fw-bold text-muted text-uppercase">Nombre</label>
                                <input type="text" name="nombre" class="form-control form-control-lg fw-bold shadow-sm" placeholder="Ej: Coca Cola" required>
                            </div>

                            <div class="row g-2 mb-3">
                                <div class="col-6">
                                    <label class="small fw-bold text-muted text-uppercase">Puntos</label>
                                    <input type="number" name="puntos" class="form-control" placeholder="1000" required>
                                </div>
                                <div class="col-6">
                                    <label class="small fw-bold text-muted text-uppercase">Stock</label>
                                    <input type="number" name="stock" class="form-control" value="10">
                                </div>
                            </div>

                            <div class="card border-0 shadow-sm mb-4">
                                <div class="card-body p-3">
                                    <label class="small fw-bold text-muted mb-2">TIPO DE PREMIO</label>
                                    
                                    <div class="form-check mb-2">
                                        <input class="form-check-input" type="radio" name="tipo_premio_radio" id="radioStock" checked onchange="toggleTipoPremio()">
                                        <label class="form-check-label" for="radioStock">Mercadería (Descuenta Stock)</label>
                                    </div>

                                    <div id="divArticulos" class="ms-3 mb-3 border-start ps-3 border-3 border-primary">
                                        <div class="mb-2">
                                            <select name="tipo_articulo" class="form-select form-select-sm mb-2" id="selectTipoArt" onchange="cargarListaArticulos()">
                                                <option value="ninguno">-- Sin vinculación --</option>
                                                <option value="producto">Producto Individual</option>
                                                <option value="combo">Combo / Pack</option>
                                            </select>
                                            
                                            <select name="id_articulo_prod" id="selProd" class="form-select form-select-sm" style="display:none;">
                                                <option value="">Seleccionar Producto...</option>
                                                <?php foreach($prods_db as $p): ?>
                                                    <option value="<?php echo $p['id']; ?>"><?php echo $p['descripcion']; ?></option>
                                                <?php endforeach; ?>
                                            </select>

                                            <select name="id_articulo_combo" id="selCombo" class="form-select form-select-sm" style="display:none;">
                                                <option value="">Seleccionar Combo...</option>
                                                <?php foreach($combos_db as $c): ?>
                                                    <option value="<?php echo $c['id']; ?>"><?php echo $c['nombre']; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="tipo_premio_radio" id="checkCupon" onchange="toggleTipoPremio()">
                                        <label class="form-check-label fw-bold text-success" for="checkCupon">Dinero en Cuenta ($)</label>
                                    </div>
                                    
                                    <div id="divMonto" style="display:none;" class="mt-2 ms-3">
                                        <div class="input-group input-group-sm">
                                            <span class="input-group-text bg-success text-white border-success fw-bold">$</span>
                                            <input type="number" step="0.01" name="monto_dinero" class="form-control border-success text-success fw-bold" placeholder="500">
                                        </div>
                                    </div>
                                    
                                    <input type="hidden" name="es_cupon" id="hiddenEsCupon" value="0">
                                </div>
                            </div>

                            <script>
                                function toggleTipoPremio() {
                                    const esDinero = document.getElementById('checkCupon').checked;
                                    document.getElementById('divMonto').style.display = esDinero ? 'block' : 'none';
                                    document.getElementById('divArticulos').style.display = esDinero ? 'none' : 'block';
                                    document.getElementById('hiddenEsCupon').value = esDinero ? 1 : 0;
                                }

                                function cargarListaArticulos() {
                                    const tipo = document.getElementById('selectTipoArt').value;
                                    const selProd = document.getElementById('selProd');
                                    const selCombo = document.getElementById('selCombo');

                                    selProd.style.display = (tipo === 'producto') ? 'block' : 'none';
                                    selCombo.style.display = (tipo === 'combo') ? 'block' : 'none';
                                    
                                    // Limpiamos selección al cambiar tipo
                                    selProd.value = "";
                                    selCombo.value = "";
                                }
                            </script>

                            <button type="submit" class="btn btn-primary w-100 fw-bold py-2 shadow-sm">
                                <i class="bi bi-save me-2"></i> GUARDAR
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-md-8">
                <div class="card card-custom h-100">
                    <div class="card-header bg-white fw-bold py-3 border-bottom d-flex justify-content-between align-items-center">
                        <span><i class="bi bi-list-check me-2 text-primary"></i> Premios Disponibles</span>
                        <span class="badge bg-light text-muted border"><?php echo count($lista); ?> items</span>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="bg-light small text-uppercase text-muted">
                                <tr>
                                    <th class="ps-4 py-3">Premio</th>
                                    <th>Vinculación (Stock)</th>
                                    <th>Costo Puntos</th>
                                    <th class="text-end pe-4">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if(count($lista) > 0): ?>
                                    <?php foreach($lista as $p): ?>
                                    <tr>
                                        <td class="ps-4">
                                            <div class="fw-bold text-dark"><?php echo htmlspecialchars($p['nombre']); ?></div>
                                            <?php if($p['es_cupon']): ?>
                                                <span class="badge bg-success bg-opacity-10 text-success border border-success border-opacity-25 small">
                                                    <i class="bi bi-cash-coin"></i> $<?php echo number_format($p['monto_dinero'], 0); ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if(!$p['es_cupon']): ?>
                                                <?php if(!empty($p['nombre_vinculo'])): ?>
                                                    <span class="badge bg-info bg-opacity-10 text-primary border border-info border-opacity-25">
                                                        <?php echo ($p['tipo_articulo']=='combo' ? '🎒 ' : '📦 ') . htmlspecialchars($p['nombre_vinculo']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary bg-opacity-10 text-muted border">Sin vincular</span>
                                                <?php endif; ?>
                                                <div class="small text-muted mt-1">Stock Virtual: <?php echo $p['stock']; ?></div>
                                            <?php else: ?>
                                                <span class="text-muted small">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge badge-puntos rounded-pill px-3 py-2">
                                                <i class="bi bi-star-fill text-dark me-1"></i> <?php echo number_format($p['puntos_necesarios'], 0, ',', '.'); ?>
                                            </span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <button onclick="confirmarBorrado(<?php echo $p['id']; ?>)" 
                                               class="btn btn-sm btn-outline-danger border-0 rounded-circle shadow-sm">
                                                <i class="bi bi-trash3-fill"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="4" class="text-center py-5 text-muted">No hay premios.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include 'includes/layout_footer.php'; ?>

    <script>
        function confirmarBorrado(id) {
            Swal.fire({
                title: '¿Eliminar premio?',
                text: "No se puede deshacer.",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#d33',
                cancelButtonColor: '#3085d6',
                confirmButtonText: 'Sí, borrar',
                cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = "gestionar_premios.php?borrar=" + id;
                }
            })
        }

        const urlParams = new URLSearchParams(window.location.search);
        if(urlParams.get('msg') === 'creado') {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Premio agregado', showConfirmButton: false, timer: 3000 });
        } else if(urlParams.get('msg') === 'borrado') {
            Swal.fire({ toast: true, position: 'top-end', icon: 'success', title: 'Premio eliminado', showConfirmButton: false, timer: 3000 });
        }
    </script>