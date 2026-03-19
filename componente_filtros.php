<?php
// includes/componente_filtros.php
// Requiere: $desde, $hasta
// Opcional: $filtros_extra (Array de filtros dinámicos)
?>
<div class="card border-0 shadow-sm rounded-4 mb-4" style="position: relative; z-index: 20;">
    <div class="card-body p-2 p-md-3">
        <form method="GET" class="d-flex flex-wrap gap-2 align-items-end w-100">
            
            <div class="flex-grow-1" style="min-width: 130px;">
                <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Desde</label>
                <input type="date" name="desde" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $desde; ?>" required>
            </div>
            <div class="flex-grow-1" style="min-width: 130px;">
                <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;">Hasta</label>
                <input type="date" name="hasta" class="form-control form-control-sm border-light-subtle fw-bold" value="<?php echo $hasta; ?>" required>
            </div>

            <?php if(!empty($filtros_extra)): foreach($filtros_extra as $f): ?>
                <div class="flex-grow-1" style="min-width: 130px;">
                    <label class="small fw-bold text-muted text-uppercase mb-1" style="font-size: 0.65rem;"><?php echo $f['label']; ?></label>
                    <select name="<?php echo $f['name']; ?>" class="form-select form-select-sm border-light-subtle fw-bold">
                        <option value="">Todos</option>
                        <?php foreach($f['options'] as $val => $text): ?>
                            <?php $selected = (isset($_GET[$f['name']]) && (string)$_GET[$f['name']] === (string)$val) ? 'selected' : ''; ?>
                            <option value="<?php echo htmlspecialchars($val); ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($text); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endforeach; endif; ?>

            <div class="flex-grow-0 mt-2 mt-md-0">
                <button type="submit" class="btn btn-primary btn-sm fw-bold rounded-3 shadow-sm w-100 px-3" style="height: 31px;">
                    <i class="bi bi-funnel-fill"></i> <span class="d-none d-md-inline">FILTRAR</span>
                </button>
            </div>
        </form>
    </div>
</div>