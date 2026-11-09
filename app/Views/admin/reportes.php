<?php
/**
 * Reportes: el formulario de filtros, las tablas en pantalla y la hoja para imprimir.
 * Las tres salen del mismo $reporte (ver app/Support/reportes.php).
 */
$tipoActual = $filtros['tipo'];
$defTipo = REPORTES_TIPOS[$tipoActual];
$usaFiltro = fn(string $f) => in_array($f, $defTipo['filtros'], true);
$alinearDerecha = fn(string $formato) => in_array($formato, ['entero', 'dinero'], true);

// Estado de la multa con el mismo color que en Sanciones: verde pagada, rojo pendiente.
$claseCelda = function (string $columna, string $texto): string {
    if ($columna !== 'Estado') {
        return '';
    }
    return match ($texto) {
        'Multa pendiente' => 'estado-mal',
        'Multa condonada' => 'estado-aviso',
        default => '',
    };
};
?>

<?php // Lo de pantalla se oculta al imprimir y en su lugar sale la hoja (mismo mecanismo
      // que Disciplina y la nómina). ?>
<div class="solo-pantalla">

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h3 class="mb-0">Reportes</h3>
        <div class="small text-muted">Elige el reporte, filtra y luego imprímelo o descárgalo en Excel.</div>
    </div>
</div>

<?php // Un botón por reporte: es más rápido de encontrar que un select, sobre todo en el celular. ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php foreach (REPORTES_TIPOS as $clave => $def): ?>
    <a href="<?= e(url('admin/reportes.php?' . http_build_query(['tipo' => $clave, 'equipo_id' => $filtros['equipo_id'], 'desde' => $filtros['desde'], 'hasta' => $filtros['hasta']]))) ?>"
       class="btn rounded-pill px-3 <?= $clave === $tipoActual ? 'btn-degradado' : 'btn-outline-secondary' ?>">
        <i class="bi <?= e($def['icono']) ?> me-1"></i><?= e($def['nombre']) ?>
    </a>
    <?php endforeach; ?>
</div>

<div class="card-suave p-4 mb-4">
    <p class="small text-muted mb-3"><i class="bi bi-info-circle me-1"></i><?= e($defTipo['descripcion']) ?></p>
    <form method="get" class="row g-2 align-items-end">
        <input type="hidden" name="tipo" value="<?= e($tipoActual) ?>">
        <?php if ($usaFiltro('equipo')): ?>
        <div class="col-12 col-md-3">
            <label class="form-label small fw-semibold mb-1" for="repEquipo">Equipo</label>
            <select name="equipo_id" id="repEquipo" class="form-select form-select-sm">
                <option value="0">Todos los equipos</option>
                <?php foreach ($equipos as $eq): ?>
                <option value="<?= (int) $eq['id'] ?>" <?= $filtros['equipo_id'] === (int) $eq['id'] ? 'selected' : '' ?>><?= e($eq['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($usaFiltro('fecha_por')): ?>
        <div class="col-6 col-md-2">
            <label class="form-label small fw-semibold mb-1" for="repFechaPor">Fechas según</label>
            <select name="fecha_por" id="repFechaPor" class="form-select form-select-sm">
                <option value="partido" <?= $filtros['fecha_por'] === 'partido' ? 'selected' : '' ?>>Fecha del partido</option>
                <option value="pago" <?= $filtros['fecha_por'] === 'pago' ? 'selected' : '' ?>>Fecha de pago</option>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($usaFiltro('fechas')): ?>
        <div class="col-6 col-md-2">
            <label class="form-label small fw-semibold mb-1" for="repDesde">Desde</label>
            <input type="date" name="desde" id="repDesde" class="form-control form-control-sm" value="<?= e($filtros['desde']) ?>">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small fw-semibold mb-1" for="repHasta">Hasta</label>
            <input type="date" name="hasta" id="repHasta" class="form-control form-control-sm" value="<?= e($filtros['hasta']) ?>">
        </div>
        <?php endif; ?>
        <?php if ($usaFiltro('estado') && isset(REPORTES_ESTADOS[$tipoActual])): ?>
        <div class="col-6 col-md-2">
            <label class="form-label small fw-semibold mb-1" for="repEstado">Mostrar</label>
            <select name="estado" id="repEstado" class="form-select form-select-sm">
                <?php foreach (REPORTES_ESTADOS[$tipoActual] as $valor => $etiqueta): ?>
                <option value="<?= e($valor) ?>" <?= $filtros['estado'] === $valor ? 'selected' : '' ?>><?= e($etiqueta) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php endif; ?>
        <div class="col-12 col-md-auto d-flex gap-2 flex-wrap">
            <button type="submit" class="btn btn-sm btn-degradado rounded-pill px-3"><i class="bi bi-funnel me-1"></i>Aplicar</button>
            <a href="<?= e(url('admin/reportes.php?tipo=' . $tipoActual)) ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">Limpiar</a>
        </div>
    </form>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="small text-muted"><i class="bi bi-funnel me-1"></i><?= e($descripcionFiltros) ?></div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-secondary rounded-pill px-3 btn-imprimir-pdf"><i class="bi bi-printer me-1"></i>Imprimir / PDF</button>
        <a href="<?= e($urlExcel) ?>" class="btn btn-outline-success rounded-pill px-3"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    </div>
</div>

<?php foreach ($reporte['secciones'] as $sec): ?>
<div class="card-suave p-4 mb-4">
    <h5 class="mb-3"><?= e($sec['titulo']) ?> <span class="badge rounded-pill text-bg-light border small fw-normal"><?= count($sec['filas']) ?></span></h5>
    <?php if (empty($sec['filas'])): ?>
        <p class="text-muted mb-0">No hay datos con estos filtros.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead>
                <tr class="small text-muted">
                    <?php foreach ($sec['columnas'] as $k => $col): ?>
                    <th class="<?= $alinearDerecha($sec['formatos'][$k] ?? '') ? 'text-end' : '' ?>"><?= e($col) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($sec['filas'] as $fila): ?>
                <tr>
                    <?php foreach ($fila as $k => $valor): $texto = reporte_celda_texto($valor, $sec['formatos'][$k] ?? 'texto', $torneo); ?>
                    <td class="<?= $alinearDerecha($sec['formatos'][$k] ?? '') ? 'text-end' : '' ?> <?= $claseCelda($sec['columnas'][$k] ?? '', $texto) ?>"><?= e($texto) ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <?php if (!empty($sec['pie'])): ?>
            <tfoot>
                <tr class="fw-bold">
                    <?php foreach ($sec['pie'] as $k => $valor): ?>
                    <td class="<?= $alinearDerecha($sec['formatos'][$k] ?? '') ? 'text-end' : '' ?>"><?= e(reporte_celda_texto($valor, $sec['formatos'][$k] ?? 'texto', $torneo)) ?></td>
                    <?php endforeach; ?>
                </tr>
            </tfoot>
            <?php endif; ?>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endforeach; ?>

</div><?php // fin de .solo-pantalla ?>

<?php // ---------- La hoja que sale en papel (o PDF) ---------- ?>
<div class="solo-impresion ficha-imprimir">
    <div class="ficha-titulo">
        <h2><?= e($reporte['titulo']) ?></h2>
        <p>
            <?= e($torneo['nombre']) ?><?= !empty($torneo['temporada']) ? ' · Temporada ' . e($torneo['temporada']) : '' ?>
            · <?= e($descripcionFiltros) ?> · Generado el <?= e(date('d/m/Y H:i')) ?>
        </p>
    </div>
    <?php foreach ($reporte['secciones'] as $sec): ?>
    <h3><?= e($sec['titulo']) ?></h3>
    <?php if (empty($sec['filas'])): ?>
    <p style="font-size:12px;">No hay datos con estos filtros.</p>
    <?php else: ?>
    <table class="ficha-tabla">
        <thead>
            <tr>
                <?php foreach ($sec['columnas'] as $col): ?><th><?= e($col) ?></th><?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sec['filas'] as $fila): ?>
            <tr>
                <?php foreach ($fila as $k => $valor): $texto = reporte_celda_texto($valor, $sec['formatos'][$k] ?? 'texto', $torneo); ?>
                <td class="<?= $claseCelda($sec['columnas'][$k] ?? '', $texto) ?>" style="<?= $alinearDerecha($sec['formatos'][$k] ?? '') ? 'text-align:right;' : '' ?>"><?= e($texto) ?></td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
            <?php if (!empty($sec['pie'])): ?>
            <tr>
                <?php foreach ($sec['pie'] as $k => $valor): ?>
                <td style="font-weight:700;<?= $alinearDerecha($sec['formatos'][$k] ?? '') ? 'text-align:right;' : '' ?>"><?= e(reporte_celda_texto($valor, $sec['formatos'][$k] ?? 'texto', $torneo)) ?></td>
                <?php endforeach; ?>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php endif; ?>
    <?php endforeach; ?>
</div>
