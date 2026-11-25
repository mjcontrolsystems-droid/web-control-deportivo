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

<?php // Todo lo de pantalla va envuelto: al imprimir se esconde y en su lugar sale la
      // hoja del reporte, que está armada para papel. Mismo mecanismo que la nómina. ?>
<div class="solo-pantalla">

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h3 class="mb-0">Disciplina</h3>
        <div class="small text-muted">Ordenado por total de tarjetas, de mayor a menor.</div>
    </div>
    <div class="d-flex align-items-center gap-2 flex-wrap">
        <?php // Filtrar por equipo es lo que se hace cuando hay que hablar con un delegado:
              // se entra a ver su plantilla y nada más. El reporte respeta el filtro, así
              // que desde aquí sale también la hoja de un solo equipo. ?>
        <form method="get" class="d-flex align-items-center gap-2 mb-0">
            <label class="small text-muted mb-0" for="filtroEquipo">Equipo</label>
            <select name="equipo_id" id="filtroEquipo" class="form-select form-select-sm" style="width:auto;" data-enviar-al-cambiar>
                <option value="0">Todos</option>
                <?php foreach ($equipos as $eq): ?>
                <option value="<?= (int) $eq['id'] ?>" <?= $equipoFiltro === (int) $eq['id'] ? 'selected' : '' ?>><?= e($eq['nombre']) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <button type="button" class="btn btn-outline-secondary rounded-pill px-3 btn-imprimir-pdf">
            <i class="bi bi-printer me-1"></i>Imprimir reporte
        </button>
    </div>
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
                Toca una fila para ver el detalle del jugador. El botón de imprimir saca este mismo
                listado como reporte, respetando el equipo que tengas filtrado.
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

        <?php // ---------- Limpieza de tarjetas ----------
              // Borrón y cuenta nueva (antes de playoffs o por fuerza mayor). No borra
              // tarjetas ni multas: el dinero y el historial quedan igual. ?>
        <div class="card-suave p-4 mt-3">
            <h5 class="mb-1"><i class="bi bi-eraser me-1"></i>Limpiar tarjetas</h5>
            <p class="small text-muted">Deja en cero las amarillas acumuladas (por ejemplo, antes de los playoffs). El dinero recaudado y el historial de tarjetas no se tocan.</p>
            <button type="button" class="btn btn-outline-danger rounded-pill px-3 w-100" data-bs-toggle="modal" data-bs-target="#modalLimpiarTarjetas">
                <i class="bi bi-eraser me-1"></i>Limpiar tarjetas acumuladas
            </button>

            <?php if (!empty($cortesDisciplina)): ?>
            <div class="mt-3">
                <div class="small fw-semibold mb-2">Limpiezas hechas</div>
                <?php foreach ($cortesDisciplina as $corte): ?>
                <div class="d-flex align-items-center gap-2 py-2 border-top small">
                    <div class="flex-grow-1">
                        <div class="fw-semibold"><?= $corte['equipo_id'] === null ? 'Todos los equipos' : e($equiposPorId[$corte['equipo_id']]['nombre'] ?? 'Equipo') ?></div>
                        <div class="text-muted">
                            Hasta el <?= e(date('d/m/Y', strtotime($corte['hasta_fecha']))) ?> ·
                            <?= $corte['modo'] === 'todo' ? 'Amarillas y suspensiones' : 'Solo amarillas acumuladas' ?>
                        </div>
                    </div>
                    <form method="post" class="mb-0" data-confirm="¿Deshacer esta limpieza? Las tarjetas de antes vuelven a contar para acumular y suspender.">
                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="accion" value="deshacer">
                        <input type="hidden" name="id" value="<?= (int) $corte['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Deshacer"><i class="bi bi-arrow-counterclockwise"></i></button>
                    </form>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php // Ventana para elegir para quién, hasta qué día y qué se limpia. ?>
<div class="modal fade" id="modalLimpiarTarjetas" tabindex="-1" aria-labelledby="tituloLimpiarTarjetas" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content" data-un-envio>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="accion" value="limpiar">
            <div class="modal-header">
                <h5 class="modal-title" id="tituloLimpiarTarjetas"><i class="bi bi-eraser me-1 text-danger"></i>Limpiar tarjetas acumuladas</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label small" for="limpiarEquipo">¿Para quién?</label>
                    <select name="equipo_id" id="limpiarEquipo" class="form-select">
                        <option value="0">Todos los equipos</option>
                        <?php foreach ($equipos as $eq): ?>
                        <option value="<?= (int) $eq['id'] ?>" <?= $equipoFiltro === (int) $eq['id'] ? 'selected' : '' ?>><?= e($eq['nombre']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label small" for="limpiarHasta">Limpiar lo jugado hasta el día</label>
                    <input type="date" name="hasta_fecha" id="limpiarHasta" class="form-control" value="<?= e(date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required>
                    <div class="form-text">Las tarjetas de partidos de ese día o antes dejan de contar. Las de después cuentan normal.</div>
                </div>
                <div class="small fw-semibold mb-2">¿Qué se limpia?</div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="modo" value="amarillas" id="modoAmarillas" checked>
                    <label class="form-check-label small" for="modoAmarillas">
                        <strong>Solo las amarillas acumuladas</strong>
                        <span class="d-block text-muted">El conteo vuelve a cero. Las suspensiones que ya salieron (por roja o por acumulación) se cumplen igual.</span>
                    </label>
                </div>
                <div class="form-check">
                    <input class="form-check-input" type="radio" name="modo" value="todo" id="modoTodo">
                    <label class="form-check-label small" for="modoTodo">
                        <strong>Todo (fuerza mayor)</strong>
                        <span class="d-block text-muted">Amarillas en cero y también se perdonan las suspensiones pendientes.</span>
                    </label>
                </div>
                <div class="alert alert-info small mb-0 mt-3">
                    <i class="bi bi-info-circle me-1"></i>No se borra ninguna tarjeta ni multa: el dinero recaudado, el historial de cada partido y esta tabla quedan igual. Se puede deshacer.
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-danger rounded-pill px-4"><i class="bi bi-eraser me-1"></i>Limpiar</button>
            </div>
        </form>
    </div>
</div>

</div><?php // fin de .solo-pantalla ?>

<?php // ---------- El reporte que sale en papel ----------
      // Armado aparte y no como "la pantalla impresa": lo que se entrega en una reunión de
      // delegados necesita encabezado, fecha y una nota de cómo se ordena. Respeta el
      // filtro de arriba, así que también sirve para la hoja de un solo equipo. ?>
<div class="solo-impresion ficha-imprimir">
    <?php require __DIR__ . '/../parciales/disciplina_hoja.php'; ?>
</div>
