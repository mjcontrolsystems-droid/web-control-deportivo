<header class="hero-copa" style="padding-bottom:3.5rem;">
    <div class="container">
        <p class="kicker mb-2"><i class="bi bi-trophy me-1"></i>Temporada <?= e($torneo['temporada']) ?></p>
        <h1 class="text-white mb-2">Tabla de <span class="text-degradado">Posiciones</span></h1>
        <p style="color:rgba(255,255,255,.75);max-width:560px;" class="mb-0">Clasificación general de <?= e($torneo['nombre']) ?>. <?php
            if (!empty($tieneGrupos)) {
                $cuantos = (int) (reset($tablasGrupo)['clasifican'] ?? 2);
                echo $cuantos === 1
                    ? 'Clasifica el primero de cada grupo a la eliminación.'
                    : 'Clasifican los primeros ' . $cuantos . ' de cada grupo a la eliminación.';
            } else {
                echo $esLiga ? 'El campeón es quien termine la temporada en el primer lugar.' : 'Los primeros lugares avanzan a la fase final.';
            }
        ?></p>
    </div>
</header>

<?php // ---------- Formato de grupos: una tabla por grupo ----------
      // Se muestran ANTES de la general porque son las que definen quién clasifica. La
      // general sigue apareciendo abajo como referencia de todo el torneo. ?>
<?php if (!empty($tieneGrupos) && !empty($tablasGrupo)): ?>
<section class="seccion pt-5 pb-0">
    <div class="container">
        <div class="row g-4">
            <?php foreach ($tablasGrupo as $letraGrupo => $datosGrupo): ?>
            <div class="col-md-6 col-xl-3">
                <div class="card-suave p-3 h-100">
                    <h5 class="mb-3">Grupo <?= e($letraGrupo) ?></h5>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0">
                            <thead>
                                <tr class="small text-muted">
                                    <th style="width:22px;">#</th>
                                    <th>Equipo</th>
                                    <th class="text-center">PJ</th>
                                    <th class="text-center">DIF</th>
                                    <th class="text-center">PTS</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($datosGrupo['tabla'] as $iFila => $filaGrupo): ?>
                                <tr class="fila-clicable <?= $iFila < (int) $datosGrupo['clasifican'] ? 'table-success' : '' ?>" data-href="<?= e(url_copa('equipo.php?id=' . (int) $filaGrupo['equipo']['id'])) ?>">
                                    <td class="small text-muted"><?= $iFila + 1 ?></td>
                                    <td class="small">
                                        <div class="d-flex align-items-center gap-2">
                                            <?= logo_equipo($filaGrupo['equipo'], 22) ?>
                                            <span class="text-truncate" style="max-width:110px;"><?= e($filaGrupo['equipo']['nombre']) ?></span>
                                        </div>
                                    </td>
                                    <td class="text-center small"><?= (int) $filaGrupo['pj'] ?></td>
                                    <td class="text-center small"><?= $filaGrupo['dif'] >= 0 ? '+' : '' ?><?= (int) $filaGrupo['dif'] ?></td>
                                    <td class="text-center small fw-bold"><?= (int) $filaGrupo['pts'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                                <?php if (empty($datosGrupo['tabla'])): ?>
                                <tr><td colspan="5" class="small text-muted">Sin equipos todavía.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <p class="small text-muted mt-3 mb-0"><i class="bi bi-square-fill text-success me-1"></i>En verde, los que clasifican a la eliminación.</p>
    </div>
</section>
<?php endif; ?>

<section class="seccion pt-5">
    <div class="container">
        <?php if (!empty($tieneGrupos)): ?>
        <h4 class="mb-3">Tabla general</h4>
        <p class="small text-muted">Todos los equipos juntos, solo como referencia: la clasificación se define dentro de cada grupo.</p>
        <?php endif; ?>
        <?php // Tabla clásica (como la de la Liga Nacional en Google): filas corridas en vez
              // de una tarjeta por equipo. En el teléfono NO se apila: se lee de un
              // vistazo, y si no cabe se desliza de lado con el equipo fijo a la izquierda.
              // PTS va justo después de PP, que es donde el ojo lo busca. ?>
        <div class="table-responsive tabla-clasica-marco">
            <table class="table tabla-posiciones tabla-clasica align-middle mb-0">
                <thead>
                    <tr>
                        <th class="col-fija col-pos">#</th>
                        <th class="col-fija col-equipo">Club</th>
                        <th class="text-center">PJ</th>
                        <th class="text-center">G</th>
                        <?php if ($torneo['permite_empates']): ?><th class="text-center">E</th><?php endif; ?>
                        <th class="text-center">P</th>
                        <th class="text-center col-pts">Pts</th>
                        <th class="text-center"><?= e(etiqueta_gf($deporte)) ?></th>
                        <th class="text-center"><?= e(etiqueta_gc($deporte)) ?></th>
                        <th class="text-center">DIF</th>
                        <th class="text-center d-none d-md-table-cell">%G</th>
                        <th class="text-center d-none d-md-table-cell" title="<?= e(etiqueta_faltas_leves($deporte)) ?>"><?= e(etiqueta_ta($deporte)) ?></th><th class="text-center d-none d-md-table-cell" title="<?= e(etiqueta_faltas_graves($deporte)) ?>"><?= e(etiqueta_tr($deporte)) ?></th>
                        <th class="text-center">Últimos</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tabla as $fila): ?>
                    <tr class="fila-clicable <?= (!$esLiga && $fila['posicion'] <= 4) ? 'zona-playoff' : '' ?>" data-href="<?= e(url_copa('equipo.php?id=' . $fila['equipo']['id'])) ?>">
                        <td class="col-fija col-pos">
                            <span class="pos-num <?= $fila['posicion'] === 1 ? 'oro' : ($fila['posicion'] === 2 ? 'plata' : ($fila['posicion'] === 3 ? 'bronce' : '')) ?>"><?= $fila['posicion'] ?></span>
                        </td>
                        <td class="col-fija col-equipo">
                            <a href="<?= url_copa('equipo.php?id=' . $fila['equipo']['id']) ?>" class="d-flex flex-nowrap align-items-center gap-2 text-decoration-none text-dark">
                                <?= logo_equipo($fila['equipo'], 28) ?>
                                <span class="nombre-club"><?= e($fila['equipo']['nombre']) ?></span>
                            </a>
                        </td>
                        <td class="text-center"><?= $fila['pj'] ?></td>
                        <td class="text-center"><?= $fila['pg'] ?></td>
                        <?php if ($torneo['permite_empates']): ?><td class="text-center"><?= $fila['pe'] ?></td><?php endif; ?>
                        <td class="text-center"><?= $fila['pp'] ?></td>
                        <td class="text-center col-pts"><?= $fila['pts'] ?></td>
                        <td class="text-center"><?= $fila['pf'] ?></td>
                        <td class="text-center"><?= $fila['pc'] ?></td>
                        <td class="text-center fw-semibold <?= $fila['dif'] > 0 ? 'text-success' : ($fila['dif'] < 0 ? 'text-danger' : 'text-muted') ?>"><?= $fila['dif'] > 0 ? '+' : '' ?><?= $fila['dif'] ?></td>
                        <td class="text-center d-none d-md-table-cell"><?= $fila['porcentaje'] ?>%</td>
                        <td class="text-center d-none d-md-table-cell"><?= $fila['tarjetas_amarillas'] ?></td>
                        <td class="text-center d-none d-md-table-cell"><?= $fila['tarjetas_rojas'] ?></td>
                        <td class="text-center col-racha">
                            <?php if (empty($fila['racha'])): ?>
                                <span class="small text-muted">—</span>
                            <?php else: ?>
                                <?php foreach ($fila['racha'] as $r): ?>
                                    <?php $claseRacha = $r === 'G' ? 'g' : ($r === 'E' ? 'e' : 'p'); ?>
                                    <?php $tituloRacha = $r === 'G' ? 'Ganó' : ($r === 'E' ? 'Empate' : 'Perdió'); ?>
                                    <?php $iconoRacha = $r === 'G' ? 'bi-check-lg' : ($r === 'E' ? 'bi-dash-lg' : 'bi-x-lg'); ?>
                                    <span class="racha-icono <?= $claseRacha ?>" title="<?= $tituloRacha ?>"><i class="bi <?= $iconoRacha ?>"></i></span>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="leyenda-racha mt-3">
            <span class="fw-semibold">Últimos 5 partidos:</span>
            <span><span class="racha-icono g"><i class="bi bi-check-lg"></i></span> Ganó</span>
            <?php if ($torneo['permite_empates']): ?><span><span class="racha-icono e"><i class="bi bi-dash-lg"></i></span> Empate</span><?php endif; ?>
            <span><span class="racha-icono p"><i class="bi bi-x-lg"></i></span> Perdió</span>
        </div>
        <div class="d-flex flex-wrap gap-4 mt-3">
            <?php if (!$esLiga): ?>
            <p class="small text-muted mb-0"><span class="d-inline-block" style="width:10px;height:10px;background:var(--color-acento);border-radius:2px;"></span> Zona de Playoffs (Top 4)</p>
            <?php endif; ?>
            <p class="small text-muted mb-0"><?= e($explicacionPuntos) ?></p>
        </div>

        <div class="card-suave p-3 mt-3">
            <p class="small fw-semibold text-muted mb-2">¿Qué significa cada columna?</p>
            <div class="row row-cols-2 row-cols-md-4 g-2">
                <div class="small text-muted"><strong class="text-dark">PJ</strong> Partidos jugados</div>
                <div class="small text-muted"><strong class="text-dark">PG</strong> Partidos ganados</div>
                <?php if ($torneo['permite_empates']): ?>
                <div class="small text-muted"><strong class="text-dark">PE</strong> Partidos empatados</div>
                <?php endif; ?>
                <div class="small text-muted"><strong class="text-dark">PP</strong> Partidos perdidos</div>
                <div class="small text-muted"><strong class="text-dark">%G</strong> Porcentaje de victorias</div>
                <div class="small text-muted"><strong class="text-dark"><?= e(etiqueta_gf($deporte)) ?></strong> <?= e(etiqueta_anotaciones($deporte)) ?> a favor</div>
                <div class="small text-muted"><strong class="text-dark"><?= e(etiqueta_gc($deporte)) ?></strong> <?= e(etiqueta_anotaciones($deporte)) ?> en contra</div>
                <div class="small text-muted"><strong class="text-dark">DIF</strong> Diferencia</div>
                <div class="small text-muted"><strong class="text-dark"><?= e(etiqueta_ta($deporte)) ?></strong> <?= e(etiqueta_faltas_leves($deporte)) ?></div>
                <div class="small text-muted"><strong class="text-dark"><?= e(etiqueta_tr($deporte)) ?></strong> <?= e(etiqueta_faltas_graves($deporte)) ?></div>
                <div class="small text-muted"><strong class="text-dark">PTS</strong> Puntos en la tabla</div>
            </div>
        </div>
    </div>
</section>
