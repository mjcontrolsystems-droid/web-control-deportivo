<?php
/**
 * Vista "Calendario" de Encuentros (panel del organizador).
 *
 * Variables que llegan desde admin/partidos.php:
 *   $calendarioMes  resultado de calendario_mes_cuadricula()
 *   $equiposPorId   equipos de la copa por id
 *
 * Todo se arma aquí en PHP (sin librerías de calendario): no hay nada que bajar de un
 * CDN y el CSP no se toca. Lo único en JS (app.js, data-cal-*) es elegir el día para la
 * agenda de abajo, que en el celular reemplaza a los encuentros dentro de cada casilla.
 */
$cal = $calendarioMes;

// Día que se muestra abierto al entrar: hoy si es de este mes; si no, el primer día del
// mes con encuentros; si no hay ninguno, el día 1.
$diaInicial = null;
foreach ($cal['semanas'] as $sem) {
    foreach ($sem as $d) {
        if ($d['delMes'] && $d['esHoy']) { $diaInicial = $d['fecha']; }
    }
}
if ($diaInicial === null) {
    foreach ($cal['semanas'] as $sem) {
        foreach ($sem as $d) {
            if ($diaInicial === null && $d['delMes'] && $d['partidos']) { $diaInicial = $d['fecha']; }
        }
    }
}
$diaInicial = $diaInicial ?? $cal['mes'] . '-01';

// Datos cortos de un encuentro para la casilla y para la agenda.
$infoPartido = function (array $p) use ($equiposPorId): array {
    $local = $equiposPorId[(int) ($p['equipo_local'] ?? 0)] ?? null;
    $visita = $equiposPorId[(int) ($p['equipo_visitante'] ?? 0)] ?? null;
    $jugado = ($p['estado'] ?? '') === 'jugado';
    $enVivo = !$jugado && ($p['cronometro_estado'] ?? '') === 'corriendo';
    return [
        'local' => $local,
        'visita' => $visita,
        'nombreLocal' => $local['nombre'] ?? 'Por definir',
        'nombreVisita' => $visita['nombre'] ?? 'Por definir',
        'siglasLocal' => calendario_siglas_equipo($local['nombre'] ?? '?'),
        'siglasVisita' => calendario_siglas_equipo($visita['nombre'] ?? '?'),
        'hora' => substr((string) ($p['hora'] ?? ''), 0, 5),
        'jugado' => $jugado,
        'enVivo' => $enVivo,
        'estadoClase' => $enVivo ? 'cal-en-vivo' : ($jugado ? 'cal-jugado' : 'cal-programado'),
        'marcador' => $jugado || $enVivo ? (int) ($p['marcador_local'] ?? 0) . ' - ' . (int) ($p['marcador_visitante'] ?? 0) : '',
        'urlFicha' => url('admin/partido_eventos.php?partido_id=' . (int) $p['id']),
        'urlEditar' => url('admin/partidos.php?accion=editar&id=' . (int) $p['id']),
        'etapa' => ($p['fase'] ?? 'grupos') !== 'grupos' && isset(FASES_LABEL[$p['fase']])
            ? FASES_LABEL[$p['fase']]
            : ((int) ($p['jornada'] ?? 0) > 0 ? 'Jornada ' . (int) $p['jornada'] : ''),
    ];
};
$urlMes = fn(string $mes) => url('admin/partidos.php?vista=calendario&mes=' . $mes);
$maxEnCasilla = 3;
?>
<div class="card-suave p-3 p-md-4 cal-encuentros" data-cal data-cal-inicial="<?= e($diaInicial) ?>">

    <?php // ---------- Encabezado: mes, flechas y Hoy ---------- ?>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <div class="d-flex align-items-center gap-2">
            <a href="<?= e($urlMes($cal['anterior'])) ?>" class="btn btn-sm btn-outline-secondary rounded-circle cal-flecha" aria-label="Mes anterior"><i class="bi bi-chevron-left"></i></a>
            <h4 class="mb-0 cal-titulo"><?= e($cal['titulo']) ?></h4>
            <a href="<?= e($urlMes($cal['siguiente'])) ?>" class="btn btn-sm btn-outline-secondary rounded-circle cal-flecha" aria-label="Mes siguiente"><i class="bi bi-chevron-right"></i></a>
            <?php if (!$cal['esMesActual']): ?>
            <a href="<?= e(url('admin/partidos.php?vista=calendario')) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3 ms-1">Hoy</a>
            <?php endif; ?>
        </div>
        <div class="small text-muted d-flex flex-wrap align-items-center gap-3">
            <span><strong class="text-body"><?= (int) $cal['totalMes'] ?></strong> encuentro<?= $cal['totalMes'] === 1 ? '' : 's' ?> este mes</span>
            <span class="d-none d-md-inline-flex align-items-center gap-2">
                <span class="cal-leyenda cal-programado"></span>Programado
                <span class="cal-leyenda cal-en-vivo"></span>En vivo
                <span class="cal-leyenda cal-jugado"></span>Jugado
            </span>
        </div>
    </div>

    <?php // ---------- Cuadrícula del mes ---------- ?>
    <div class="cal-grilla" role="grid" aria-label="Encuentros de <?= e($cal['titulo']) ?>">
        <?php foreach (CALENDARIO_DIAS_CORTOS as $i => $nombreDia): ?>
        <div class="cal-cabecera <?= $i >= 5 ? 'cal-finde' : '' ?>" role="columnheader"><?= e($nombreDia) ?></div>
        <?php endforeach; ?>

        <?php foreach ($cal['semanas'] as $sem): ?>
            <?php foreach ($sem as $d): ?>
            <?php
                $cantidad = count($d['partidos']);
                $clases = ['cal-dia'];
                if (!$d['delMes']) { $clases[] = 'cal-fuera'; }
                if ($d['esHoy']) { $clases[] = 'cal-hoy'; }
                if ($d['finDeSemana']) { $clases[] = 'cal-finde'; }
                if ($cantidad > 0) { $clases[] = 'cal-con-partidos'; }
            ?>
            <div class="<?= implode(' ', $clases) ?>" role="gridcell" data-cal-dia="<?= e($d['fecha']) ?>" tabindex="0"
                 <?= $d['delMes'] ? '' : 'data-cal-ir="' . e($urlMes(substr($d['fecha'], 0, 7))) . '"' ?>
                 aria-label="<?= (int) $d['dia'] ?>: <?= $cantidad ?> encuentro<?= $cantidad === 1 ? '' : 's' ?>">
                <div class="cal-dia-top">
                    <span class="cal-numero"><?= (int) $d['dia'] ?></span>
                    <?php foreach ($d['jornadas'] as $j): ?>
                    <span class="cal-jornada">J<?= (int) $j ?></span>
                    <?php endforeach; ?>
                </div>

                <?php // Computadora: los encuentros dentro de la casilla (los primeros 3). ?>
                <div class="cal-partidos">
                    <?php foreach (array_slice($d['partidos'], 0, $maxEnCasilla) as $p): $i = $infoPartido($p); ?>
                    <a href="<?= e($i['urlFicha']) ?>" class="cal-chip <?= $i['estadoClase'] ?>" title="<?= e($i['nombreLocal'] . ' vs ' . $i['nombreVisita'] . ($i['hora'] ? ' · ' . $i['hora'] : '')) ?>">
                        <?php if ($i['hora'] !== ''): ?><span class="cal-chip-hora"><?= e($i['hora']) ?></span><?php endif; ?>
                        <span class="cal-chip-equipos"><?= e($i['siglasLocal']) ?> <span class="cal-chip-vs"><?= $i['marcador'] !== '' ? e(str_replace(' ', '', $i['marcador'])) : 'vs' ?></span> <?= e($i['siglasVisita']) ?></span>
                    </a>
                    <?php endforeach; ?>
                    <?php if ($cantidad > $maxEnCasilla): ?>
                    <button type="button" class="cal-mas" data-cal-elegir="<?= e($d['fecha']) ?>">+<?= $cantidad - $maxEnCasilla ?> más</button>
                    <?php endif; ?>
                </div>

                <?php // Celular: puntitos de color, uno por encuentro (máximo 4). ?>
                <?php if ($cantidad > 0): ?>
                <div class="cal-puntos" aria-hidden="true">
                    <?php foreach (array_slice($d['partidos'], 0, 4) as $p): $i = $infoPartido($p); ?>
                    <span class="cal-punto <?= $i['estadoClase'] ?>"></span>
                    <?php endforeach; ?>
                    <?php if ($cantidad > 4): ?><span class="cal-punto-mas">+</span><?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>

    <?php // ---------- Agenda del día elegido ----------
          // Un bloque por día del mes (ocultos) y app.js muestra el que se toca. En el
          // celular es la forma de ver los encuentros; en la computadora es el detalle. ?>
    <div class="cal-agenda mt-3">
        <?php foreach ($cal['semanas'] as $sem): ?>
            <?php foreach ($sem as $d): if (!$d['delMes']) { continue; } ?>
            <?php $tsDia = strtotime($d['fecha']); ?>
            <div class="cal-agenda-dia <?= $d['fecha'] === $diaInicial ? '' : 'd-none' ?>" data-cal-agenda="<?= e($d['fecha']) ?>">
                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                    <h6 class="mb-0">
                        <?= e(CALENDARIO_DIAS_LARGOS[(int) date('N', $tsDia) - 1]) ?> <?= (int) $d['dia'] ?> de <?= e(mb_strtolower(CALENDARIO_MESES[(int) date('n', $tsDia)])) ?>
                        <?php if ($d['esHoy']): ?><span class="badge rounded-pill text-bg-primary ms-1">Hoy</span><?php endif; ?>
                        <?php foreach ($d['jornadas'] as $j): ?><span class="badge rounded-pill text-bg-light border ms-1">Jornada <?= (int) $j ?></span><?php endforeach; ?>
                    </h6>
                    <?php if (puede('partidos_editar')): ?>
                    <a href="<?= e(url('admin/partidos.php?accion=nuevo&fecha=' . $d['fecha'])) ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3">
                        <i class="bi bi-plus-lg me-1"></i>Programar este día
                    </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($d['partidos'])): ?>
                <div class="text-muted small py-2">Sin encuentros este día.</div>
                <?php else: ?>
                <div class="d-flex flex-column gap-2">
                    <?php foreach ($d['partidos'] as $p): $i = $infoPartido($p); ?>
                    <div class="cal-agenda-item <?= $i['estadoClase'] ?>">
                        <div class="cal-agenda-hora">
                            <?= $i['hora'] !== '' ? e($i['hora']) : '<span class="text-muted">—</span>' ?>
                            <?php if ($i['enVivo']): ?><span class="d-block cal-vivo-txt">EN VIVO</span><?php endif; ?>
                        </div>
                        <a href="<?= e($i['urlFicha']) ?>" class="cal-agenda-equipos text-reset text-decoration-none">
                            <span class="cal-agenda-equipo"><?= $i['local'] ? logo_equipo($i['local'], 24) : '' ?><span class="text-truncate"><?= e($i['nombreLocal']) ?></span></span>
                            <span class="cal-agenda-marcador"><?= $i['marcador'] !== '' ? e($i['marcador']) : 'vs' ?></span>
                            <span class="cal-agenda-equipo cal-agenda-visita"><span class="text-truncate"><?= e($i['nombreVisita']) ?></span><?= $i['visita'] ? logo_equipo($i['visita'], 24) : '' ?></span>
                        </a>
                        <div class="cal-agenda-extra small text-muted">
                            <?php if ($i['etapa'] !== ''): ?><span><?= e($i['etapa']) ?></span><?php endif; ?>
                            <?php if (!empty($p['cancha'])): ?><span><i class="bi bi-geo-alt me-1"></i><?= e((string) $p['cancha']) ?></span><?php endif; ?>
                        </div>
                        <div class="cal-agenda-acciones">
                            <a href="<?= e($i['urlFicha']) ?>" class="btn btn-sm btn-outline-secondary" title="Ficha del partido (goles, tarjetas)"><i class="bi bi-clipboard-data"></i></a>
                            <?php if (puede('partidos_editar')): ?>
                            <a href="<?= e($i['urlEditar']) ?>" class="btn btn-sm btn-outline-secondary" title="Editar fecha, hora o cancha"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    </div>

    <?php if ($cal['sinFecha'] > 0): ?>
    <p class="small text-muted mt-3 mb-0"><i class="bi bi-info-circle me-1"></i><?= (int) $cal['sinFecha'] ?> encuentro<?= $cal['sinFecha'] === 1 ? '' : 's' ?> sin fecha no aparece<?= $cal['sinFecha'] === 1 ? '' : 'n' ?> en el calendario. Búscalo<?= $cal['sinFecha'] === 1 ? '' : 's' ?> en la vista de lista.</p>
    <?php endif; ?>
</div>
