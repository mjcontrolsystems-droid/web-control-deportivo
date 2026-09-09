<?php
/**
 * Disciplina: quién lleva más tarjetas.
 *
 * Se abre para responder tres preguntas concretas: a quién hay que llamarle la atención,
 * quién no puede jugar la próxima fecha, y qué equipo se está yendo de las manos.
 */
$etAmarilla = etiqueta_ta($torneo['deporte'] ?? null);
$etRoja = etiqueta_tr($torneo['deporte'] ?? null);
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h3 class="mb-0">Disciplina</h3>
        <div class="small text-muted">Ordenado por total de tarjetas, de mayor a menor.</div>
    </div>
    <?php // Filtrar por equipo es lo que se hace cuando hay que hablar con un delegado:
          // se entra a ver su plantilla y nada más. ?>
    <form method="get" class="d-flex align-items-center gap-2">
        <label class="small text-muted mb-0" for="filtroEquipo">Equipo</label>
        <select name="equipo_id" id="filtroEquipo" class="form-select form-select-sm" style="width:auto;" data-enviar-al-cambiar>
            <option value="0">Todos</option>
            <?php foreach ($equipos as $eq): ?>
            <option value="<?= (int) $eq['id'] ?>" <?= $equipoFiltro === (int) $eq['id'] ? 'selected' : '' ?>><?= e($eq['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="stat-tile text-center"><span class="stat-icono"><i class="bi bi-square-fill text-warning"></i></span><div class="fs-4 fw-bold"><?= (int) $totalAmarillas ?></div><div class="small text-muted"><?= e($etAmarilla) ?>s</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-tile text-center"><span class="stat-icono"><i class="bi bi-square-fill text-danger"></i></span><div class="fs-4 fw-bold"><?= (int) $totalRojas ?></div><div class="small text-muted"><?= e($etRoja) ?>s</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-tile text-center"><span class="stat-icono"><i class="bi bi-person-x"></i></span><div class="fs-4 fw-bold"><?= count($suspendidosAhora) ?></div><div class="small text-muted">No juegan la próxima</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-tile text-center"><span class="stat-icono"><i class="bi bi-exclamation-triangle"></i></span><div class="fs-4 fw-bold"><?= (int) $alBorde ?></div><div class="small text-muted">A una de suspensión</div></div></div>
</div>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card-suave p-4">
            <h5 class="mb-3"><i class="bi bi-person-lines-fill me-1"></i>Jugadores</h5>

            <?php if (empty($ranking)): ?>
                <p class="text-muted mb-0">Todavía no hay tarjetas registradas<?= $equipoFiltro > 0 ? ' para este equipo' : '' ?>.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead>
                        <tr class="small text-muted">
                            <th style="width:52px;"></th>
                            <th>Jugador</th>
                            <th class="text-center"><?= e($etAmarilla) ?></th>
                            <th class="text-center"><?= e($etRoja) ?></th>
                            <th class="text-center">Total</th>
                            <th>Situación</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($ranking as $f): $jid = (int) $f['jugador']['id']; ?>
                        <tr class="fila-clicable" data-href="<?= e(url_copa('jugador.php?id=' . $jid)) ?>">
                            <td><?= foto_jugador($f['jugador'], 36) ?></td>
                            <td>
                                <div class="fw-semibold">#<?= e($f['jugador']['dorsal']) ?> <?= e($f['jugador']['nombre']) ?></div>
                                <div class="small text-muted"><?= e($f['equipo']['nombre'] ?? '') ?></div>
                            </td>
                            <td class="text-center"><?= (int) $f['amarillas'] ?: '—' ?></td>
                            <td class="text-center <?= $f['rojas'] > 0 ? 'text-danger fw-bold' : '' ?>"><?= (int) $f['rojas'] ?: '—' ?></td>
                            <td class="text-center fw-bold"><?= (int) $f['total'] ?></td>
                            <td class="small">
                                <?php // En orden de urgencia: primero quien no juega, después
                                      // quien está a punto de no jugar. ?>
                                <?php if (isset($suspendidosAhora[$jid])): ?>
                                    <span class="badge rounded-pill text-bg-danger">
                                        <i class="bi bi-person-x me-1"></i>No juega la próxima
                                    </span>
                                <?php elseif (!empty($f['acumulacion']['al_borde'])): ?>
                                    <span class="badge rounded-pill text-bg-warning text-dark">
                                        A una de suspensión
                                    </span>
                                <?php elseif (!empty($f['acumulacion']) && $f['acumulacion']['hacia_suspension'] > 0): ?>
                                    <span class="text-muted">
                                        <?= (int) $f['acumulacion']['hacia_suspension'] ?> de <?= (int) torneo_amarillas_para_suspension($torneo) ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="small text-muted mt-3 mb-0">
                <i class="bi bi-info-circle me-1"></i>Se ordena por total y se desempata por
                <?= e(mb_strtolower($etRoja)) ?>s: cuatro tarjetas no son lo mismo si una es <?= e(mb_strtolower($etRoja)) ?>.
                Toca una fila para ver el detalle del jugador.
            </p>
            <?php endif; ?>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card-suave p-4">
            <h5 class="mb-1"><i class="bi bi-people me-1"></i>Por equipo</h5>
            <p class="small text-muted">Para ver quién se está yendo de las manos antes de que haya un problema en la cancha.</p>

            <?php if (empty($rankingEquipos)): ?>
                <p class="text-muted mb-0">Sin tarjetas todavía.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr class="small text-muted">
                            <th>Equipo</th>
                            <th class="text-center">A</th>
                            <th class="text-center">R</th>
                            <th class="text-center">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rankingEquipos as $f): ?>
                        <tr class="fila-clicable" data-href="<?= e(url('admin/disciplina.php?equipo_id=' . (int) $f['equipo']['id'])) ?>">
                            <td class="small"><?= e($f['equipo']['nombre']) ?></td>
                            <td class="text-center small"><?= (int) $f['amarillas'] ?></td>
                            <td class="text-center small <?= $f['rojas'] > 0 ? 'text-danger fw-bold' : '' ?>"><?= (int) $f['rojas'] ?></td>
                            <td class="text-center fw-bold"><?= (int) $f['total'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>
