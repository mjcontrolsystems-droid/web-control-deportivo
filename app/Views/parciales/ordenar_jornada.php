<?php
/**
 * Panel para reordenar los horarios de una jornada (va dentro del bloque de la jornada en
 * admin/partidos.php). Cada día es una lista: se arrastra una tarjeta por el asa, o se
 * usan las flechas ↑ ↓ (en el teléfono arrastrar es incómodo). La hora se ve recalculada
 * al momento, pero NO se guarda hasta pulsar "Guardar orden"; el servidor vuelve a
 * calcular las horas por su cuenta y valida todo (ver calendario_reordenar_jornada).
 *
 * Espera: $numJornada, $lista (encuentros de la jornada), $equiposPorId, $configCalendario.
 */
$diasOrden = calendario_dias_de_jornada($lista, $configCalendario);
$movibles = array_filter($diasOrden, fn($d) => !$d['bloqueado']);
?>
<div class="collapse" id="ordenar<?= (int) $numJornada ?>">
    <div class="card-suave p-3 mt-2 mb-2 border border-primary-subtle">
        <?php if ($movibles === []): ?>
            <p class="small text-muted mb-0"><i class="bi bi-lock me-1"></i>Todos los días de esta jornada ya tienen encuentros jugados: sus horarios no se mueven.</p>
        <?php else: ?>
        <form method="post" class="mb-0" data-ordenar-jornada>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="accion" value="reordenar_jornada">
            <input type="hidden" name="jornada" value="<?= (int) $numJornada ?>">

            <p class="small text-muted mb-3">
                <i class="bi bi-info-circle me-1"></i>Arrastra por el asa <i class="bi bi-grip-vertical"></i> o usa las flechas.
                Puedes pasar un encuentro de un día al otro. Las horas se acomodan solas; nada cambia hasta que pulses <strong>Guardar orden</strong>.
            </p>

            <div class="row g-3">
                <?php foreach ($diasOrden as $dia): ?>
                <div class="col-md-6">
                    <div class="small fw-bold text-uppercase mb-1">
                        <?= e(formatear_fecha_larga($dia['fecha'])) ?>
                        <span class="text-muted fw-normal text-lowercase">· desde <?= e($dia['inicio']) ?>, cada <?= (int) $dia['intervalo'] ?> min</span>
                    </div>
                    <?php if ($dia['bloqueado']): ?>
                    <p class="small text-muted mb-1"><i class="bi bi-lock me-1"></i>Tiene encuentros jugados: este día no se mueve.</p>
                    <?php endif; ?>
                    <ul class="list-group lista-orden<?= $dia['bloqueado'] ? ' bloqueada' : '' ?>"
                        data-fecha="<?= e($dia['fecha']) ?>"
                        data-inicio="<?= e($dia['inicio']) ?>"
                        data-intervalo="<?= (int) $dia['intervalo'] ?>"
                        data-simultaneos="<?= max(1, count($dia['canchas'])) ?>"
                        style="min-height:48px;">
                        <?php foreach ($dia['partidos'] as $p):
                            $loc = $equiposPorId[$p['equipo_local']]['nombre'] ?? '¿?';
                            $vis = $equiposPorId[$p['equipo_visitante']]['nombre'] ?? '¿?'; ?>
                        <li class="list-group-item d-flex align-items-center gap-2 py-2"
                            data-id="<?= (int) $p['id'] ?>"
                            data-equipos="<?= (int) $p['equipo_local'] ?>,<?= (int) $p['equipo_visitante'] ?>">
                            <?php if (!$dia['bloqueado']): ?>
                            <span class="asa-orden text-muted" style="cursor:grab;" title="Arrastrar"><i class="bi bi-grip-vertical fs-5"></i></span>
                            <?php endif; ?>
                            <span class="hora-orden fw-bold small" style="min-width:3.2rem;"><?= e(substr((string) $p['hora'], 0, 5)) ?></span>
                            <span class="small flex-grow-1"><?= e($loc) ?> <span class="text-muted">vs</span> <?= e($vis) ?></span>
                            <?php if (!$dia['bloqueado']): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" data-mover="arriba" aria-label="Subir <?= e($loc) ?> vs <?= e($vis) ?>"><i class="bi bi-arrow-up"></i></button>
                            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" data-mover="abajo" aria-label="Bajar <?= e($loc) ?> vs <?= e($vis) ?>"><i class="bi bi-arrow-down"></i></button>
                            <?php else: ?>
                            <span class="badge text-bg-light"><?= ($p['estado'] ?? '') === 'jugado' ? 'Jugado' : 'Fijo' ?></span>
                            <?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="alert alert-danger small py-2 mt-3 mb-0 d-none" data-aviso-choque>
                <i class="bi bi-exclamation-triangle me-1"></i>Un equipo quedó jugando dos veces el mismo día (marcado en rojo). Muévelo antes de guardar.
            </div>

            <div class="d-flex gap-2 mt-3 flex-wrap">
                <button type="submit" class="btn btn-degradado rounded-pill px-4" data-guardar-orden disabled><i class="bi bi-check2 me-1"></i>Guardar orden</button>
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-deshacer-orden disabled><i class="bi bi-arrow-counterclockwise me-1"></i>Deshacer</button>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>
