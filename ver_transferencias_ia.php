<?php
// Ni un solo espacio o salto de línea antes del <?php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { 
    header("Location: index.php"); 
    exit; 
}
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);
$permisos = $_SESSION['permisos'] ?? [];

// --- CANDADO DE ACCESO IA ---
if (!$es_admin && !in_array('ia_aprobar_pendiente', $permisos) && !in_array('ia_escanear_comprobante', $permisos) && !in_array('ia_validar_gemini', $permisos)) { 
    header("Location: dashboard.php"); exit; 
}

// ==========================================
// 1. MOTOR DE BORRADO (AJAX POST) - INTACTO
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitud_borrar'])) {
    if (ob_get_length()) ob_clean(); 
    
    $ids = json_decode($_POST['ids_a_borrar'], true);
    if (!empty($ids) && is_array($ids)) {
        try {
            $interrogantes = implode(',', array_fill(0, count($ids), '?'));
            $sql = "DELETE FROM transferencias WHERE id IN ($interrogantes)";
            $stmt = $conexion->prepare($sql);
            $stmt->execute($ids);
            echo "EXITO";
        } catch (Exception $e) {
            echo "ERROR: " . $e->getMessage();
        }
    } else {
        echo "ERROR: Lista de IDs vacía.";
    }
    exit; 
}

// ==========================================
// 1.6. MOTOR DE APROBAR PENDIENTES
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['solicitud_aprobar_pendiente'])) {
    if (!$es_admin && !in_array('ia_aprobar_pendiente', $permisos)) { die("Sin permiso para impactar pagos."); }
    if (ob_get_length()) ob_clean(); 
    $id_transf = intval($_POST['id_transf']);
    
    try {
        $conexion->beginTransaction();
        $stmt = $conexion->prepare("SELECT datos_json FROM transferencias WHERE id = ?");
        $stmt->execute([$id_transf]);
        $tr = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($tr) {
            $d = json_decode($tr['datos_json'], true);
            $id_venta = $d['id_venta'] ?? null;
            
            if ($id_venta) {
                // 1. Pasamos la venta a completada
                $conexion->prepare("UPDATE ventas SET estado = 'completada' WHERE id = ?")->execute([$id_venta]);
                
                // 2. Sumamos el dinero a la caja (Sea venta normal o sorteo)
                if (isset($d['id_caja']) && isset($d['total_venta'])) {
                    $conexion->prepare("UPDATE cajas_sesion SET total_ventas = total_ventas + ? WHERE id = ?")->execute([$d['total_venta'], $d['id_caja']]);
                }
                
                // 3. Marcamos la transferencia
                $d['estado'] = 'completada';
                $conexion->prepare("UPDATE transferencias SET datos_json = ? WHERE id = ?")->execute([json_encode($d, JSON_UNESCAPED_UNICODE), $id_transf]);
                $conexion->commit();
                echo "EXITO"; exit;
            }
        }
        throw new Exception("Datos incompletos para aprobar.");
    } catch (Exception $e) {
        $conexion->rollBack();
        echo "ERROR: " . $e->getMessage(); exit;
    }
}

// ==========================================
// 2. CARGA DE REGISTROS Y LISTA NEGRA
// ==========================================
$buscar = $_GET['buscar'] ?? '';
$estado_filtro = $_GET['estado'] ?? ''; 
$desde  = $_GET['desde'] ?? '';
$hasta  = $_GET['hasta'] ?? '';

$query = "SELECT * FROM transferencias WHERE 1=1";
if ($estado_filtro === 'pendiente') {
    $query .= " AND datos_json LIKE '%\"estado\":\"pendiente\"%'";
} elseif ($buscar) {
    $buscar_e = addslashes($buscar);
    $query .= " AND (datos_json LIKE '%$buscar_e%' OR monto LIKE '%$buscar_e%')";
}
if ($desde) {
    $desde_e = addslashes($desde);
    $query .= " AND DATE(fecha_registro) >= '$desde_e'";
}
if ($hasta) {
    $hasta_e = addslashes($hasta);
    $query .= " AND DATE(fecha_registro) <= '$hasta_e'";
}
$query .= " ORDER BY id DESC";

// --- MOTOR DE PAGINACIÓN ---
$pagina_actual = isset($_GET['pagina']) ? max(1, (int)$_GET['pagina']) : 1;
$limite_por_pagina = 30;
$offset = ($pagina_actual - 1) * $limite_por_pagina;

$query_count = str_replace("SELECT *", "SELECT COUNT(*)", $query);
$total_registros = $conexion->query($query_count)->fetchColumn();
$total_paginas = ceil($total_registros / $limite_por_pagina);

$query .= " LIMIT $limite_por_pagina OFFSET $offset";
$transferencias = $conexion->query($query)->fetchAll(PDO::FETCH_ASSOC);

$lista_negra = [];
try {
    $lista_negra = $conexion->query("SELECT * FROM lista_negra_cbu ORDER BY fecha_bloqueo DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch(Exception $e) {}

// ESTADÍSTICAS PARA EL BANNER AZUL
$total_transf = count($transferencias);
$monto_total = 0;
foreach($transferencias as $t) { $monto_total += (float)$t['monto']; }
$total_bloqueados = count($lista_negra);

include 'includes/layout_header.php'; 
?>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<style>
    .oculto { display: none !important; }
    /* ESTILOS DEL MODAL QUIRÚRGICO PARA LA FOTO */
    #modalImagen { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; overflow: auto; background-color: rgba(0,0,0,0.85); }
    #modalImagen .cerrar { position: absolute; top: 15px; right: 35px; color: #f1f1f1; font-size: 40px; font-weight: bold; cursor: pointer; z-index: 10000; }
    #modalImagen .cerrar:hover { color: #d9534f; }
    #modalImagen .controles { position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%); display: flex; gap: 15px; z-index: 10000; background: rgba(0,0,0,0.6); padding: 10px 20px; border-radius: 10px; }
    .btn-zoom { background: #333; color: white; border: 1px solid #777; padding: 10px 20px; cursor: pointer; border-radius: 5px; font-size: 18px; font-weight: bold; }
    .btn-zoom:hover { background: #555; }
    .contenedor-img { width: 100%; min-height: 100%; display: flex; align-items: center; justify-content: center; overflow: visible; padding: 50px; }
    .imagen-contenido { max-width: 90%; max-height: 80vh; transition: transform 0.2s ease; box-shadow: 0 0 20px rgba(0,0,0,0.5); }
    .btn-nav-img { background: rgba(0,0,0,0.7); color: white; border: none; padding: 15px; cursor: pointer; font-size: 24px; font-weight: bold; position: absolute; top: 50%; transform: translateY(-50%); z-index: 10001; }
    .btn-nav-img:hover { background: rgba(0,0,0,0.9); }
    #btnPrevImg { left: 20px; }
    #btnNextImg { right: 20px; }
    
    .op-truncado {
        display: inline-block;
        max-width: 120px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        vertical-align: bottom;
    }

    /* DISEÑO MÓVIL ULTRA COMPACTO */
    @media (max-width: 768px) {
        .tabla-movil-ajustada td, .tabla-movil-ajustada th { 
            padding: 0.4rem 0.2rem !important; 
        }
        .tabla-movil-ajustada { font-size: 0.75rem !important; }
        .op-truncado { max-width: 60px; } 
        .monto-texto { font-size: 0.9rem !important; }
        .btn-accion-movil { font-size: 0.7rem !important; padding: 0.2rem 0.4rem !important; }
        .info-extra-movil { font-size: 0.7rem !important; line-height: 1.1; margin-bottom: 2px; }
        .nom-movil { font-size: 0.8rem !important; line-height: 1.1; margin-bottom: 2px; }
        .ocultar-movil { display: none !important; }
    }
</style>

<?php
// --- BANNER AZUL DINÁMICO ESTILO CUPONES ---
$titulo = "Ver Transferencias (IA)";
$subtitulo = "Auditoría, validación automática y prevención de fraudes.";
$icono_bg = "bi-bank";

$query_filtros = "desde=$desde&hasta=$hasta&buscar=$buscar";
$botones = [
    ['texto' => 'Reporte PDF', 'link' => "reporte_transferencias.php?$query_filtros", 'icono' => 'bi-file-earmark-pdf-fill', 'class' => 'btn btn-danger fw-bold rounded-pill px-3 px-md-4 py-2 shadow-sm', 'target' => '_blank']
];

$widgets = [
    ['label' => 'Transf. Recibidas', 'valor' => $total_transf, 'icono' => 'bi-arrow-down-left-circle', 'icon_bg' => 'bg-success bg-opacity-20'],
    ['label' => 'Monto Total', 'valor' => '$' . number_format($monto_total, 0, ',', '.'), 'icono' => 'bi-currency-dollar', 'icon_bg' => 'bg-white bg-opacity-10'],
    ['label' => 'Bloqueados', 'valor' => $total_bloqueados, 'icono' => 'bi-shield-fill-x', 'border' => 'border-danger', 'icon_bg' => 'bg-danger bg-opacity-20']
];

include 'includes/componente_banner.php'; 
?>

<div class="container-fluid mt-n4 pb-5 px-2 px-md-4" style="position: relative; z-index: 20;">
    
    <div class="card border-0 shadow-sm rounded-4 mb-3 bg-warning text-dark overflow-hidden" style="border-left: 5px solid #ff9800 !important;">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="row g-2 align-items-center mb-0">
                <input type="hidden" name="desde" value="<?= htmlspecialchars($desde) ?>">
                <input type="hidden" name="hasta" value="<?= htmlspecialchars($hasta) ?>">
                
                <div class="col-md-8 col-12 text-center text-md-start">
                    <h6 class="fw-bold mb-1 text-uppercase"><i class="bi bi-search me-2"></i>Buscador Rápido</h6>
                    <p class="small mb-0 opacity-75 d-none d-md-block">Ingresá el DNI, nombre, CBU o monto exacto.</p>
                </div>
                <div class="col-md-4 col-12 text-end">
                    <div class="input-group input-group-sm">
                        <input type="text" name="buscar" class="form-control border-0 fw-bold shadow-none" placeholder="Buscar transferencia..." value="<?= htmlspecialchars($buscar) ?>">
                        <button class="btn btn-dark px-3 border-0" type="submit"><i class="bi bi-arrow-right-circle-fill"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow-sm rounded-4 mb-4">
        <div class="card-body p-2 p-md-3">
            <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100">
                <input type="hidden" name="buscar" value="<?= htmlspecialchars($buscar) ?>">
                
                <div class="flex-grow-1" style="min-width: 120px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Desde</label>
                    <input type="date" name="desde" class="form-control form-control-sm border-light-subtle fw-bold" value="<?= $desde ?>">
                </div>
                <div class="flex-grow-1" style="min-width: 120px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Hasta</label>
                    <input type="date" name="hasta" class="form-control form-control-sm border-light-subtle fw-bold" value="<?= $hasta ?>">
                </div>
                
                <div class="w-100 d-flex flex-wrap justify-content-between gap-2 mt-2 mt-md-0">
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm px-3"><i class="bi bi-funnel-fill"></i> <span class="ocultar-movil">FILTRAR</span></button>
                        <a href="ver_transferencias_ia.php" class="btn btn-light btn-sm fw-bold rounded-3 border px-3"><i class="bi bi-trash3-fill"></i> <span class="ocultar-movil">LIMPIAR</span></a>
                        <a href="?estado=pendiente" class="btn btn-warning btn-sm fw-bold rounded-3 border px-3 shadow-sm"><i class="bi bi-clock-history"></i> VER PENDIENTES</a>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-dark btn-sm fw-bold shadow-sm rounded-3 text-nowrap" onclick="abrirModalListaNegra()">
                            <i class="bi bi-shield-lock-fill me-1"></i> L. NEGRA
                        </button>
                        <button type="button" id="botonMasivo" class="btn btn-danger btn-sm fw-bold shadow-sm rounded-3 text-nowrap oculto" onclick="borrarSeleccionados()">
                            <i class="bi bi-trash3-fill"></i> (<span id="cuenta">0</span>)
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>


    <div class="alert py-2 small mb-4 text-center fw-bold border-0 shadow-sm rounded-3" style="background-color: #e9f2ff; color: #102A57;">
        <i class="bi bi-hand-index-thumb-fill me-1"></i> Toca o haz clic en un registro para ver el ticket comprobante
    </div>

    <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-5">
        <div class="card-header bg-white fw-bold py-3 border-bottom d-flex justify-content-between align-items-center">
            <span><i class="bi bi-bank me-2 text-primary"></i> Registros de Comprobantes</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0 tabla-movil-ajustada">
                <thead class="bg-light text-uppercase text-muted" style="font-size: 0.75rem;">
                    <tr>
                        <th class="text-center ps-2">
                            <input type="checkbox" id="checkPrincipal" class="form-check-input" onclick="alternarTodos(this)" style="transform: scale(1.2); cursor: pointer;">
                        </th>
                        <th>FECHA / OP</th>
                        <th>MONTO</th>
                        <th>ORIGEN</th>
                        <th>DESTINO</th>
                        <th class="text-center">ADJUNTO</th>
                        <th class="text-end pe-3">ACCIÓN</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach($transferencias as $t): 
                        $d = json_decode($t['datos_json'], true);
                        
                        $op = $d['op'] ?? $d['operacion'] ?? $d['nro_op'] ?? '-';
                        $nom_emisor = $d['nom_e'] ?? '-';
                        $doc_emisor = $d['doc_e'] ?? '-';
                        $cbu_emisor = $d['cbu_e'] ?? '-';
                        $banco      = $d['banco_e'] ?? '-';
                        
                        $nom_receptor = $d['nom_r'] ?? '-';
                        $doc_receptor = $d['doc_r'] ?? '-';
                        $cbu_receptor = $d['cbu_r'] ?? '-';

                        $texto = $t['texto_completo'] ?? '';
                        $ruta_foto_cruda = $t['imagen_base64'] ?? ''; 

                        $array_fotos_final = [];
                        if (!empty($ruta_foto_cruda)) {
                            $es_json = json_decode($ruta_foto_cruda, true);
                            if (is_array($es_json)) {
                                foreach($es_json as $img) { if(!empty(trim($img))) $array_fotos_final[] = trim($img); }
                            } else {
                                $array_temp = preg_split('/[,;|]/', $ruta_foto_cruda);
                                foreach($array_temp as $img) {
                                    $img_limpia = trim(str_replace(['"', "'", '[', ']'], '', $img)); 
                                    if(!empty($img_limpia)) $array_fotos_final[] = $img_limpia;
                                }
                            }
                        }
                        $json_fotos_btn = htmlspecialchars(json_encode($array_fotos_final), ENT_QUOTES, 'UTF-8');

                        if (stripos($texto, 'MODO') !== false || stripos($t['datos_json'] ?? '', 'MODO') !== false) {
                            $doc_emisor = '-'; $doc_receptor = '-'; $cbu_emisor = '-'; 
                            if (preg_match('/Ref\.\s*([a-zA-Z0-9\-]+)/i', $texto, $m)) $op = trim($m[1]);
                            if (preg_match('/Transferencia de\s+(.*?)\s+Desde la cuenta/i', $texto, $m)) $nom_emisor = trim($m[1]);
                            if (preg_match('/Desde la cuenta\s+(.*?)\s*(?:•|\.|CA|CC|\d)/i', $texto, $m)) $banco = 'MODO / ' . trim($m[1]); else $banco = 'MODO';
                            if (preg_match('/Para\s+(.*?)\s+A su cuenta/i', $texto, $m)) $nom_receptor = trim($m[1]);
                            if (preg_match('/CBU\/CVU\s*(\d{22})/i', $texto, $m)) $cbu_receptor = trim($m[1]);
                        } 
                        elseif (stripos($banco, 'Nación') !== false || stripos($banco, 'BNA') !== false) {
                            if ($doc_emisor !== '-' && $doc_emisor !== '---') {
                                $doc_receptor = $doc_emisor; $doc_emisor = '-';           
                            }
                        }

                        $t_json = [
                            'id' => $t['id'],
                            'op' => $op,
                            'emisor' => $nom_emisor,
                            'doc_e' => $doc_emisor,
                            'cbu_e' => $cbu_emisor,
                            'banco' => $banco,
                            'receptor' => $nom_receptor,
                            'doc_r' => $doc_receptor,
                            'cbu_r' => $cbu_receptor,
                            'monto' => number_format((float)$t['monto'], 2, ',', '.'),
                            'fecha' => date('d/m/Y H:i', strtotime($t['fecha_registro'])),
                            'fotos' => $array_fotos_final
                        ];
                        $json_t_btn = htmlspecialchars(json_encode($t_json), ENT_QUOTES, 'UTF-8');
                        $es_pendiente = (isset($d['estado']) && $d['estado'] === 'pendiente');
                        $bg_fila = $es_pendiente ? 'background-color: #fff3cd !important;' : '';
                    ?>
                    <tr id="fila-<?= $t['id'] ?>" onclick='verTicketTransferencia(<?= $json_t_btn ?>)' style="cursor: pointer; transition: background 0.2s; <?= $bg_fila ?>">
                        <td class="text-center ps-2" onclick="event.stopPropagation();">
                            <input type="checkbox" class="form-check-input checkFila" value="<?= $t['id'] ?>" onclick="revisarChecks()" style="transform: scale(1.2); cursor: pointer;">
                        </td>
                        <td>
                            <div class="fw-bold text-dark info-extra-movil">
                                <i class="bi bi-calendar-event text-muted me-1 ocultar-movil"></i><?= date('d/m/y H:i', strtotime($t['fecha_registro'])) ?>
                                <?php if($es_pendiente): ?><span class="badge bg-warning text-dark ms-1 shadow-sm">PENDIENTE</span><?php endif; ?>
                            </div>
                            <div class="text-muted font-monospace info-extra-movil d-flex align-items-center">
                                <span class="me-1 fw-bold">OP:</span>
                                <span class="op-truncado"><?= htmlspecialchars($op) ?></span>
                                <?php if($op !== '-'): ?>
                                <button type="button" class="btn btn-sm btn-link p-0 ms-1 text-info" onclick="Swal.fire('Nº de Operación', '<?= htmlspecialchars($op) ?>', 'info')" title="Ver OP completo">
                                    <i class="bi bi-info-circle-fill"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-link p-0 ms-1 text-primary" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($op) ?>'); Swal.fire({toast:true, position:'top-end', icon:'success', title:'Copiado', showConfirmButton:false, timer:1500});" title="Copiar OP">
                                    <i class="bi bi-copy"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                        
                        <td>
                            <div class="text-success fw-bold monto-texto">$<?= number_format((float)$t['monto'], 2, ',', '.') ?></div>
                        </td>
                        
                        <td>
                            <div class="fw-bold text-primary nom-movil"><?= htmlspecialchars($nom_emisor) ?></div>
                            <div class="text-muted info-extra-movil"><i class="bi bi-bank me-1 ocultar-movil"></i><?= htmlspecialchars($banco) ?></div>
                            <div class="font-monospace text-muted info-extra-movil">CBU: <?= htmlspecialchars($cbu_emisor) ?></div>
                            <div class="font-monospace text-muted info-extra-movil ocultar-movil">DNI: <?= htmlspecialchars($doc_emisor) ?></div>
                        </td>
                        
                        <td>
                            <div class="fw-bold text-dark nom-movil"><?= htmlspecialchars($nom_receptor) ?></div>
                            <div class="font-monospace text-muted info-extra-movil">CBU: <?= htmlspecialchars($cbu_receptor) ?></div>
                            <div class="font-monospace text-muted info-extra-movil ocultar-movil">DNI: <?= htmlspecialchars($doc_receptor) ?></div>
                        </td>
                        
                        <td class="text-center" onclick="event.stopPropagation();">
                            <?php if (!empty($array_fotos_final)): ?>
                                <button type="button" class="btn btn-outline-primary fw-bold rounded-pill shadow-sm btn-sm px-3" onclick='abrirVisorMulti(<?= $json_fotos_btn ?>)'>
                                    <i class="bi bi-image me-1"></i> FOTO
                                </button>
                            <?php else: ?>
                                <span class="text-muted info-extra-movil">Sin Foto</span>
                            <?php endif; ?>
                        </td>

                        <td class="text-end pe-3" onclick="event.stopPropagation();">
                            <?php if($es_admin): ?>
                                <div class="d-flex justify-content-end gap-1 flex-wrap">
                                    <?php if($es_pendiente): ?>
                                        <button type="button" class="btn btn-success rounded-pill fw-bold shadow-sm px-2 py-1 me-1" style="font-size: 0.75rem;" title="Aprobar Dinero" onclick="aprobarPendiente(<?= $t['id'] ?>); event.stopPropagation();">
                                            <i class="bi bi-check-circle-fill"></i> IMPACTÓ
                                        </button>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-outline-danger rounded-circle border-0 shadow-sm p-1 d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;" title="Eliminar Registro" onclick="borrarId(<?= $t['id'] ?>)">
                                        <i class="bi bi-trash3-fill" style="font-size: 0.8rem;"></i>
                                    </button>
                                    <button type="button" class="btn btn-danger rounded-circle border-0 shadow-sm p-1 d-flex align-items-center justify-content-center" style="width: 28px; height: 28px;" title="¡Bloquear Estafador!" onclick="mandarListaNegra(<?= $t['id'] ?>, '<?= $cbu_emisor ?>')">
                                        <i class="bi bi-shield-fill-x" style="font-size: 0.8rem;"></i>
                                    </button>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <?php if(empty($transferencias)): ?>
                    <tr>
                        <td colspan="7" class="text-center py-5 text-muted">
                            <i class="bi bi-inbox opacity-25" style="font-size: 4rem;"></i>
                            <h5 class="mt-2">No se encontraron transferencias.</h5>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php if (isset($total_paginas) && $total_paginas > 1): 
            $query_params = $_GET; unset($query_params['pagina']);
            $qs = http_build_query($query_params); $qs = $qs ? "&$qs" : "";
        ?>
        <div class="card-footer bg-white border-top py-3">
            <nav>
                <ul class="pagination justify-content-center mb-0 shadow-sm">
                    <li class="page-item <?= ($pagina_actual <= 1) ? 'disabled' : '' ?>">
                        <a class="page-link fw-bold" href="?pagina=<?= $pagina_actual - 1 ?><?= $qs ?>">Anterior</a>
                    </li>
                    <?php 
                    $inicio_pag = max(1, $pagina_actual - 2);
                    $fin_pag = min($total_paginas, $pagina_actual + 2);
                    for($i = $inicio_pag; $i <= $fin_pag; $i++): 
                    ?>
                        <li class="page-item <?= ($i == $pagina_actual) ? 'active' : '' ?>">
                            <a class="page-link fw-bold" href="?pagina=<?= $i ?><?= $qs ?>"><?= $i ?></a>
                        </li>
                    <?php endfor; ?>
                    <li class="page-item <?= ($pagina_actual >= $total_paginas) ? 'disabled' : '' ?>">
                        <a class="page-link fw-bold" href="?pagina=<?= $pagina_actual + 1 ?><?= $qs ?>">Siguiente</a>
                    </li>
                </ul>
            </nav>
        </div>
        <?php endif; ?>
    </div>
</div>

<div id="modalImagen">
    <span class="cerrar" onclick="cerrarVisor()">&times;</span>
    <button type="button" class="btn-nav-img" id="btnPrevImg" onclick="cambiarImagen(-1)" style="display:none;">&#10094;</button>
    <button type="button" class="btn-nav-img" id="btnNextImg" onclick="cambiarImagen(1)" style="display:none;">&#10095;</button>
    <div class="contenedor-img">
        <img class="imagen-contenido" id="imgAmpliacion">
    </div>
    <div class="controles">
        <span id="contadorImg" style="color: white; font-weight: bold; align-self: center; margin-right: 15px;"></span>
        <button type="button" class="btn-zoom" onclick="hacerZoom(0.2)">+</button>
        <button type="button" class="btn-zoom" onclick="hacerZoom(-0.2)">-</button>
        <button type="button" class="btn-zoom" onclick="resetZoom()">Restablecer</button>
    </div>
</div>

<script>
// ==========================================
// VISOR DE IMÁGENES CON ZOOM Y MÚLTIPLES FOTOS
// ==========================================
var nivelZoom = 1;
var imgAmpliacion = document.getElementById("imgAmpliacion");
var listaImagenesActual = [];
var indiceImagenActual = 0;

function abrirVisorMulti(listaImagenes) {
    if (!listaImagenes || listaImagenes.length === 0) return;
    
    listaImagenesActual = listaImagenes;
    indiceImagenActual = 0;
    
    document.getElementById("modalImagen").style.display = "block";
    mostrarImagenActual();
}

function mostrarImagenActual() {
    imgAmpliacion.src = listaImagenesActual[indiceImagenActual];
    resetZoom();
    
    // Actualizar contador
    var contadorText = "";
    if (listaImagenesActual.length > 1) {
        contadorText = (indiceImagenActual + 1) + " / " + listaImagenesActual.length;
        document.getElementById("btnPrevImg").style.display = "block";
        document.getElementById("btnNextImg").style.display = "block";
    } else {
        document.getElementById("btnPrevImg").style.display = "none";
        document.getElementById("btnNextImg").style.display = "none";
    }
    document.getElementById("contadorImg").innerText = contadorText;
}

function cambiarImagen(direccion) {
    indiceImagenActual += direccion;
    
    // Ciclar si llegamos al final o principio
    if (indiceImagenActual >= listaImagenesActual.length) {
        indiceImagenActual = 0;
    } else if (indiceImagenActual < 0) {
        indiceImagenActual = listaImagenesActual.length - 1;
    }
    
    mostrarImagenActual();
}

function cerrarVisor() {
    document.getElementById("modalImagen").style.display = "none";
    imgAmpliacion.src = "";
    listaImagenesActual = [];
}

function hacerZoom(cambio) {
    nivelZoom += cambio;
    if(nivelZoom < 0.2) nivelZoom = 0.2;
    if(nivelZoom > 3) nivelZoom = 3;
    aplicarZoom();
}

function resetZoom() {
    nivelZoom = 1;
    aplicarZoom();
}

function aplicarZoom() {
    imgAmpliacion.style.transform = "scale(" + nivelZoom + ")";
}

// Cerrar el modal tocando fuera de la imagen o apretando Escape
window.onclick = function(event) {
    var modal = document.getElementById('modalImagen');
    if (event.target == modal) {
        cerrarVisor();
    }
}
document.addEventListener('keydown', function(event){
    if(event.key === "Escape"){ cerrarVisor(); }
    // Flechas para cambiar imagen
    if(document.getElementById("modalImagen").style.display === "block" && listaImagenesActual.length > 1) {
        if(event.key === "ArrowRight") { cambiarImagen(1); }
        if(event.key === "ArrowLeft") { cambiarImagen(-1); }
    }
});
// ==========================================
// 3. FUNCIONES DE JAVASCRIPT REPARADAS (INTACTAS)
// ==========================================
function alternarTodos(maestro) {
    var cuadros = document.querySelectorAll('.checkFila');
    cuadros.forEach(function(c) {
        c.checked = maestro.checked;
    });
    revisarChecks();
}

function revisarChecks() {
    var marcados = document.querySelectorAll('.checkFila:checked').length;
    var btn = document.getElementById('botonMasivo');
    var span = document.getElementById('cuenta');
    
    if(span) span.innerHTML = marcados;
    
    if(btn) {
        if(marcados > 0) {
            btn.classList.remove('oculto');
        } else {
            btn.classList.add('oculto');
        }
    }
}

function borrarId(id) {
    procesarEnvio([id]);
}

function borrarSeleccionados() {
    var seleccionados = [];
    document.querySelectorAll('.checkFila:checked').forEach(function(c) {
        seleccionados.push(c.value);
    });
    if(seleccionados.length > 0) {
        procesarEnvio(seleccionados);
    } else {
        Swal.fire('Aviso', 'No marcaste ningún registro', 'info');
    }
}

function procesarEnvio(lista) {
    Swal.fire({
        title: '¿Confirmar borrado?',
        text: "Vas a eliminar " + lista.length + " registro(s).",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'SÍ, ELIMINAR',
        cancelButtonText: 'CANCELAR'
    }).then((result) => {
        if (result.isConfirmed) {
            
            var formData = new FormData();
            formData.append('solicitud_borrar', '1');
            formData.append('ids_a_borrar', JSON.stringify(lista));

            fetch(window.location.pathname, {
                method: 'POST',
                body: formData
            })
            .then(function(respuesta) {
                return respuesta.text();
            })
            .then(function(texto) {
                if(texto.includes("EXITO")) {
                    Swal.fire(
                        '¡Listo!',
                        'Eliminado correctamente.',
                        'success'
                    ).then(() => {
                        window.location.reload();
                    });
                } else {
                    console.log("Respuesta oculta del server:", texto);
                    Swal.fire('Error del servidor', 'La base de datos devolvió un error. Revisá la consola.', 'error');
                }
            })
            .catch(function(error) {
                Swal.fire('Fallo de conexión', 'No se pudo procesar: ' + error.message, 'error');
            });
        }
    });
}

function aprobarPendiente(idTransf) {
    Swal.fire({
        title: '¿Confirmar Impacto?',
        text: "La plata entrará a la caja del día y la venta quedará confirmada.",
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        cancelButtonText: 'Cancelar',
        confirmButtonText: 'Sí, ya impactó'
    }).then((result) => {
        if (result.isConfirmed) {
            var formData = new FormData();
            formData.append('solicitud_aprobar_pendiente', '1');
            formData.append('id_transf', idTransf);

            fetch(window.location.pathname, { method: 'POST', body: formData })
            .then(res => res.text())
            .then(texto => {
                if(texto.includes("EXITO")) {
                    Swal.fire('¡Aprobada!', 'La transferencia ha sido confirmada en caja.', 'success').then(() => window.location.reload());
                } else {
                    Swal.fire('Error', 'No se pudo aprobar: ' + texto, 'error');
                }
            });
        }
    });
}

// ==========================================
// NUEVAS FUNCIONES: LISTA NEGRA Y CORREO
// ==========================================
function mandarListaNegra(idTransf, cbuEmisor) {
    if (cbuEmisor === '-' || cbuEmisor === '---' || cbuEmisor === '') {
        Swal.fire('Atención', 'No se detectó un CBU origen válido en este comprobante para bloquear.', 'warning');
        return;
    }

    Swal.fire({
        title: '¡ALERTA DE FRAUDE!',
        html: `¿Estás seguro que querés bloquear el CBU:<br><b class="text-danger fs-5">${cbuEmisor}</b>?<br><br>Esto anulará este registro y <b>bloqueará futuras compras</b> con este comprobante.`,
        icon: 'error',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-shield-fill-x"></i> SÍ, BLOQUEAR Y ANULAR',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            var formData = new FormData();
            formData.append('solicitud_bloquear', '1');
            formData.append('id_transf', idTransf);
            formData.append('cbu', cbuEmisor);

            fetch(window.location.pathname, { method: 'POST', body: formData })
            .then(res => res.text())
            .then(texto => {
                if(texto.includes("EXITO")) {
                    Swal.fire('¡Bloqueado!', 'El estafador fue agregado a la Lista Negra.', 'success').then(() => window.location.reload());
                } else {
                    Swal.fire('Error', 'No se pudo bloquear.', 'error');
                }
            }).catch(err => Swal.fire('Error', 'Problema de conexión.', 'error'));
        }
    });
}

function enviarMailTransferencia(id) {
    Swal.fire({ 
        title: '<i class="bi bi-envelope-fill text-primary"></i> Enviar Comprobante', 
        text: 'Ingrese el correo del destinatario:',
        input: 'email', 
        showCancelButton: true,
        confirmButtonText: 'ENVIAR COMPROBANTE',
        cancelButtonText: 'CANCELAR',
        confirmButtonColor: '#0d6efd'
    }).then((r) => {
        if(r.isConfirmed && r.value) {
            Swal.fire({ title: 'Enviando correo...', html: 'Por favor, espere...', allowOutsideClick: false, didOpen: () => { Swal.showLoading(); } });
            
            let fData = new FormData(); 
            fData.append('id', id); 
            fData.append('email', r.value);
            
            fetch('acciones/enviar_email_transferencia.php', { method: 'POST', body: fData })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    Swal.fire('¡Enviado!', 'El comprobante se envió correctamente.', 'success');
                } else {
                    Swal.fire('Error', data.msg, 'error');
                }
            })
            .catch(() => Swal.fire('Error', 'No se pudo conectar con el servidor de correos.', 'error'));
        }
    });
}
</script>

<div class="modal fade" id="modalListaNegra" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-lg rounded-4">
            <div class="modal-header bg-dark text-white border-0 py-3">
                <h5 class="modal-title fw-bold"><i class="bi bi-shield-lock-fill text-danger me-2"></i> Estafadores Bloqueados</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0">
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="bg-light text-muted small text-uppercase" style="position: sticky; top: 0; z-index: 1;">
                            <tr>
                                <th class="ps-4">CBU / ALIAS BLOQUEADO</th>
                                <th>FECHA DEL BLOQUEO</th>
                                <th class="text-end pe-4">ACCIÓN</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($lista_negra)): ?>
                                <tr><td colspan="3" class="text-center py-4 text-muted">La lista negra está limpia actualmente.</td></tr>
                            <?php else: ?>
                                <?php foreach($lista_negra as $ln): ?>
                                <tr>
                                    <td class="ps-4 fw-bold font-monospace text-danger"><?= htmlspecialchars($ln['cbu']) ?></td>
                                    <td class="text-muted small"><i class="bi bi-calendar-x me-1"></i> <?= date('d/m/Y H:i', strtotime($ln['fecha_bloqueo'])) ?></td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-success fw-bold rounded-pill px-3 shadow-sm" onclick="desbloquearCBU('<?= htmlspecialchars($ln['cbu']) ?>')">
                                            <i class="bi bi-unlock-fill me-1"></i> DESBLOQUEAR
                                        </button>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function abrirModalListaNegra() {
    var modal = new bootstrap.Modal(document.getElementById('modalListaNegra'));
    modal.show();
}

function desbloquearCBU(cbu) {
    Swal.fire({
        title: '¿Desbloquear CBU?',
        html: `¿Estás seguro que querés perdonar y desbloquear el CBU:<br><b class="text-success fs-5">${cbu}</b>?<br><br>Este cliente podrá volver a pagar con transferencia usando la Inteligencia Artificial sin restricciones.`,
        icon: 'question',
        showCancelButton: true,
        confirmButtonColor: '#198754',
        cancelButtonColor: '#6c757d',
        confirmButtonText: '<i class="bi bi-unlock-fill"></i> SÍ, DESBLOQUEAR',
        cancelButtonText: 'Cancelar'
    }).then((result) => {
        if (result.isConfirmed) {
            var formData = new FormData();
            formData.append('solicitud_desbloquear', '1');
            formData.append('cbu', cbu);

            fetch(window.location.pathname, { method: 'POST', body: formData })
            .then(res => res.text())
            .then(texto => {
                if(texto.includes("EXITO")) {
                    Swal.fire('¡Desbloqueado!', 'El CBU fue removido exitosamente de la Lista Negra.', 'success').then(() => window.location.reload());
                } else {
                    Swal.fire('Error', 'No se pudo desbloquear el CBU.', 'error');
                }
            }).catch(err => Swal.fire('Error', 'Problema de conexión con el servidor.', 'error'));
        }
    });
}

<?php
$conf = $conexion->query("SELECT nombre_negocio, direccion_local, cuit, logo_url, color_barra_nav, telefono_whatsapp FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC);

// BÚSQUEDA 100% DINÁMICA: DUEÑO (id_rol = 2)
$stmtDueño = $conexion->query("SELECT u.id, u.nombre_completo, r.nombre as nombre_rol FROM usuarios u JOIN roles r ON u.id_rol = r.id WHERE u.id_rol = 2 LIMIT 1");
$dueño = $stmtDueño->fetch(PDO::FETCH_ASSOC);

$aclaracion_op = "";
$ruta_firma = "";

if ($dueño) {
    $aclaracion_op = strtoupper($dueño['nombre_completo'] . " | " . $dueño['nombre_rol']);
    if (file_exists("img/firmas/usuario_" . $dueño['id'] . ".png")) {
        $ruta_firma = "img/firmas/usuario_" . $dueño['id'] . ".png";
    }
}

if (empty($ruta_firma)) {
    $ruta_firma = "img/firmas/firma_admin.png";
}
if (empty($aclaracion_op)) {
    $aclaracion_op = "OPERADOR AUTORIZADO";
}
?>

const miLocal = <?php echo json_encode($conf); ?>;
const miFirma = "<?php echo file_exists($ruta_firma) ? $ruta_firma : ''; ?>";
const opAclaracion = "<?php echo $aclaracion_op; ?>";

function verTicketTransferencia(t) {
    let fechaF = t.fecha;
    let linkPdfPublico = window.location.origin + "/ticket_transferencia_pdf.php?id=" + t.id + "&v=" + Date.now();
    let qrUrl = `https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=2&data=` + encodeURIComponent(linkPdfPublico);
    
    let logoHtml = miLocal.logo_url ? `<img src="${miLocal.logo_url}?v=${Date.now()}" style="max-height: 50px; margin-bottom: 10px;">` : '';
    let rutaFirmaOp = miFirma ? miFirma + `?v=${Date.now()}` : `img/firmas/firma_admin.png?v=${Date.now()}`;
    let firmaHtml = `<img src="${rutaFirmaOp}" style="max-height: 80px; margin-bottom: -25px;" onerror="this.style.display='none'"><br><div style="border-top:1px solid #000; width:100%; margin-top:5px;"></div><small style="font-size:9px; font-weight:bold;">${opAclaracion}</small>`;

    let val = (v) => v && v.trim() !== '' && v.trim() !== '-' && v.trim() !== '---' && !v.toLowerCase().includes('no detectado');

    let htmlDatos = `<div style="margin-bottom: 6px;"><strong>FECHA:</strong> ${fechaF}</div>`;
    
    if (val(t.emisor)) htmlDatos += `<div style="margin-bottom: 2px;"><strong>ORIGEN:</strong> <span style="text-transform: uppercase;">${t.emisor}</span></div>`;
    if (val(t.cbu_e)) htmlDatos += `<div style="margin-bottom: 2px;">&nbsp;&nbsp;<small>CBU: ${t.cbu_e}</small></div>`;
    if (val(t.doc_e)) htmlDatos += `<div style="margin-bottom: 6px;">&nbsp;&nbsp;<small>DNI/CUIT: ${t.doc_e}</small></div>`;
    
    if (val(t.banco)) htmlDatos += `<div style="margin-bottom: 6px;"><strong>BANCO:</strong> <span style="text-transform: uppercase;">${t.banco}</span></div>`;
    
    if (val(t.receptor)) htmlDatos += `<div style="margin-bottom: 2px;"><strong>DESTINO:</strong> <span style="text-transform: uppercase;">${t.receptor}</span></div>`;
    if (val(t.cbu_r)) htmlDatos += `<div style="margin-bottom: 2px;">&nbsp;&nbsp;<small>CBU: ${t.cbu_r}</small></div>`;
    if (val(t.doc_r)) htmlDatos += `<div style="margin-bottom: 6px;">&nbsp;&nbsp;<small>DNI/CUIT: ${t.doc_r}</small></div>`;
    
    htmlDatos += `<div style="margin-top: 8px; border-top: 1px dashed #ccc; padding-top: 6px;"><strong>AUDITOR:</strong> ${opAclaracion}</div>`;

    let ticketHTML = `
        <div id="printTicket" style="font-family: 'Inter', sans-serif; text-align: left; color: #000; padding: 10px;">
            <div style="text-align: center; border-bottom: 2px dashed #ccc; padding-bottom: 15px; margin-bottom: 15px;">
                ${logoHtml}
                <h4 style="font-weight: 900; margin: 0; text-transform: uppercase;">${miLocal.nombre_negocio || 'MI NEGOCIO'}</h4>
                <small style="color: #666;">CUIT: ${miLocal.cuit || 'S/N'}<br>${miLocal.direccion_local || ''}</small>
            </div>
            <div style="text-align: center; margin-bottom: 15px;">
                <h5 style="font-weight: 900; color: #198754; letter-spacing: 1px; margin:0;">COMPROBANTE TRANSFERENCIA</h5>
                <span style="font-size: 10px; background: #eee; padding: 2px 6px; border-radius: 4px;">OP #${t.op}</span>
            </div>
            <div style="background: #f8f9fa; border: 1px solid #eee; padding: 10px; border-radius: 8px; margin-bottom: 15px; font-size: 13px;">
                ${htmlDatos}
            </div>
            <div style="background: #19875410; border-left: 4px solid #198754; padding: 12px; display:flex; justify-content:space-between; align-items:center; margin-bottom: 20px;">
                <span style="font-size: 1.1em; font-weight:800;">TOTAL:</span>
                <span style="font-size: 1.15em; font-weight:900; color: #198754;">$${t.monto}</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: flex-end; margin-top: 20px; padding-top: 15px; border-top: 2px dashed #eee;">
                <div style="width: 45%; text-align: center;">${firmaHtml}</div>
                <div style="width: 45%; text-align: center;">
                    <a href="${linkPdfPublico}" target="_blank">
                        <img src="${qrUrl}" alt="QR" style="width: 75px; height: 75px; border: 1px solid #ddd; padding: 3px; border-radius: 5px;">
                    </a>
                    <div style="font-size: 8px; color: #999; margin-top: 3px;">ESCANEAR PDF</div>
                </div>
            </div>
        </div>
        
        <div class="d-flex justify-content-center gap-2 mt-4 border-top pt-3 no-print">
            <button class="btn btn-sm btn-outline-dark fw-bold" onclick="window.open('${linkPdfPublico}', '_blank')"><i class="bi bi-file-earmark-pdf-fill"></i> PDF</button>
            <button class="btn btn-sm btn-success fw-bold" onclick="mandarWATransferencia('${t.op}', '${t.monto}', '${linkPdfPublico}', '${encodeURIComponent(JSON.stringify(t.fotos))}')"><i class="bi bi-whatsapp"></i> WA</button>
            <button class="btn btn-sm btn-primary fw-bold" onclick="enviarMailTransferencia(${t.id})"><i class="bi bi-envelope"></i> EMAIL</button>
        </div>
    `;

    Swal.fire({ html: ticketHTML, width: 400, showConfirmButton: false, showCloseButton: true, background: '#fff' });
}

function mandarWATransferencia(op, monto, link, fotosCodificadas) {
    let fotos = [];
    try { fotos = JSON.parse(decodeURIComponent(fotosCodificadas)); } catch(e){}
    let linkFoto = '';
    if (fotos.length > 0 && !fotos[0].startsWith('data:')) {
        let base = window.location.origin + window.location.pathname.replace('ver_transferencias_ia.php', '');
        linkFoto = `\n🖼️ Captura original:\n${base}${fotos[0]}\n`;
    }

    let msj = `*Comprobante de Transferencia Validada* ✅\nOperación: *${op}*\nMonto: *$${monto}*\n\n📄 Ver ticket oficial:\n${link}\n${linkFoto}\n_Auditado por Vanguard POS_`;
    let tel = miLocal.telefono_whatsapp ? miLocal.telefono_whatsapp.replace(/[^0-9]/g, '') : '';
    let url = tel ? `https://wa.me/${tel}?text=${encodeURIComponent(msj)}` : `https://wa.me/?text=${encodeURIComponent(msj)}`;
    window.open(url, '_blank');
}
</script>

<?php include 'includes/layout_footer.php'; ?>