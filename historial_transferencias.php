<?php
// historial_transferencias.php
session_start();
require_once 'includes/db.php';

if (!isset($_SESSION['usuario_id'])) { header("Location: index.php"); exit; }
$es_admin = (($_SESSION['rol'] ?? 3) <= 2);

// Traer comprobantes ordenados por fecha
$sql = "SELECT * FROM comprobantes_transferencia ORDER BY fecha DESC LIMIT 100";
$comprobantes = $conexion->query($sql)->fetchAll(PDO::FETCH_ASSOC);

$color_sistema = '#102A57';
try { $resColor = $conexion->query("SELECT color_barra_nav FROM configuracion WHERE id=1")->fetch(PDO::FETCH_ASSOC); if (isset($resColor['color_barra_nav'])) $color_sistema = $resColor['color_barra_nav']; } catch (Exception $e) { }

include 'includes/layout_header.php';
?>

<div class="header-blue" style="background: <?php echo $color_sistema; ?> !important; border-radius: 0 !important; width: 100vw; margin-left: calc(-50vw + 50%); padding: 40px 0; margin-bottom: 20px;">
    <div class="container position-relative">
        <div class="d-flex justify-content-between align-items-center mb-0">
            <div>
                <h2 class="font-cancha mb-0 text-white"><i class="bi bi-bank2 me-2"></i>Historial de Transferencias</h2>
                <p class="opacity-75 mb-0 text-white small">Registro de comprobantes escaneados en caja.</p>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <div class="card shadow-sm border-0 rounded-4">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 text-center">
                    <thead class="table-light">
                        <tr>
                            <th>Fecha y Hora</th>
                            <th>Monto</th>
                            <th>CUIT / CUIL</th>
                            <th>CVU / CBU</th>
                            <th>Operación</th>
                            <th>Estado</th>
                            <th>Comprobante</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($comprobantes as $c): ?>
                        <tr>
                            <td><small class="fw-bold text-muted"><?php echo date('d/m/Y H:i', strtotime($c['fecha'])); ?></small></td>
                            <td class="text-success fw-bold">$<?php echo number_format($c['monto_esperado'], 2, ',', '.'); ?></td>
                            <td><?php echo $c['cuit_cuil'] ?: '<span class="text-muted small">-No detectado-</span>'; ?></td>
                            <td><?php echo $c['cvu_cbu'] ? substr($c['cvu_cbu'],0,6).'***'.substr($c['cvu_cbu'],-4) : '<span class="text-muted small">-No detectado-</span>'; ?></td>
                            <td><?php echo $c['numero_operacion'] ?: '-'; ?></td>
                            <td><span class="badge <?php echo strpos($c['estado'], 'Automático') !== false ? 'bg-success' : 'bg-warning text-dark'; ?>"><?php echo $c['estado']; ?></span></td>
                            <td>
                                <?php if($c['imagen_ruta']): ?>
                                    <button class="btn btn-sm btn-outline-primary shadow-sm" onclick="verFoto('<?php echo $c['imagen_ruta']; ?>')"><i class="bi bi-image"></i> Ver Foto</button>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if(empty($comprobantes)): ?>
                            <tr><td colspan="7" class="py-4 text-muted">Aún no hay comprobantes escaneados.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    function verFoto(ruta) {
        Swal.fire({
            title: 'Comprobante',
            imageUrl: ruta,
            imageAlt: 'Comprobante escaneado',
            imageWidth: '100%',
            confirmButtonText: 'Cerrar',
            confirmButtonColor: '#102A57'
        });
    }
</script>

<?php include 'includes/layout_footer.php'; ?>