<?php
/**
 * Perfil público del jugador: sus números de la temporada.
 */
?>
<header class="hero-copa" style="padding-bottom:2.5rem;">
    <div class="container">
        <p class="kicker mb-2"><i class="bi bi-person me-1"></i><?= e(forma_genero($torneo['genero'] ?? null, 'Jugador', 'Jugadora')) ?> de <?= e($equipo['nombre'] ?? '') ?></p>
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <?php // La foto preside el perfil, con el escudo del equipo al lado. Es lo que
                  // convierte esta página en algo comprobable: quien duda de si el que va a
                  // entrar es de la promoción, abre esto en el teléfono y compara la cara. ?>
            <?= foto_jugador($jugador, 96, 'foto-jugador--hero', true) ?>
            <?php if ($equipo): ?><?= logo_equipo($equipo, 56) ?><?php endif; ?>
            <div>
                <h1 class="text-white mb-1">#<?= e($jugador['dorsal']) ?> <?= e($jugador['nombre']) ?></h1>
                <p style="color:rgba(255,255,255,.75);" class="mb-0">
                    <?= e(posicion_label($deporte, (string) ($jugador['posicion'] ?? '')) ?: 'Sin posición asignada') ?>
                    <?= empty($jugador['activo']) ? ' · Inactivo' : '' ?>
                </p>
            </div>
        </div>
        <div class="mt-3">
            <a href="<?= url_copa('equipo.php?id=' . (int) ($equipo['id'] ?? 0)) ?>" class="btn btn-outline-luz btn-sm rounded-pill px-3">Volver a <?= e($equipo['nombre'] ?? 'su equipo') ?></a>
        </div>
    </div>
</header>

<section class="seccion pt-4">
    <div class="container" style="max-width:900px;">

        <?php // Lo urgente arriba: si no puede jugar la próxima fecha, es lo primero a saber. ?>
        <?php if ($suspension !== null): ?>
        <div class="alert alert-danger rounded-4 border-0 shadow-sm d-flex align-items-start gap-2">
            <i class="bi bi-slash-circle-fill mt-1"></i>
            <div>
                <div class="fw-semibold">Suspendido para el próximo encuentro</div>
                <?php // 'detalle' es la explicación legible ("Roja directa, cumple 1 de 2"...);
                      // 'motivo' es la clave técnica y solo sirve de respaldo. ?>
                <div class="small"><?= e((string) ($suspension['detalle'] ?? ($suspension['motivo'] ?? 'Por sanción disciplinaria'))) ?></div>
            </div>
        </div>
        <?php elseif (!empty($acumulacion['al_borde'])): ?>
        <?php // Solo se avisa cuando falta UNA. Decirle a alguien que va 1 de 3 es ruido;
              // decirle que va 2 de 3 le cambia cómo entra a la cancha. ?>
        <div class="alert alert-warning rounded-4 border-0 shadow-sm d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle-fill mt-1"></i>
            <div>
                <div class="fw-semibold">A una amarilla de la suspensión</div>
                <div class="small">
                    Lleva <?= (int) $acumulacion['hacia_suspension'] ?> amarillas acumuladas.
                    Con la siguiente se pierde <?= (int) torneo_partidos_suspension_amarillas($torneo) === 1
                        ? 'el próximo partido'
                        : (int) torneo_partidos_suspension_amarillas($torneo) . ' partidos' ?>.
                </div>
            </div>
        </div>
        <?php elseif ($cobraMultas && $debe > 0): ?>
        <div class="alert alert-warning rounded-4 border-0 shadow-sm d-flex align-items-start gap-2">
            <i class="bi bi-cash-coin mt-1"></i>
            <div>
                <div class="fw-semibold">Tiene multas pendientes por <?= e(sancion_monto_texto($torneo, $debe)) ?></div>
                <div class="small">Se pagan en la mesa antes del próximo encuentro.</div>
            </div>
        </div>
        <?php endif; ?>

        <?php // ---------- Números de la temporada ---------- ?>
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="stat-tile text-center h-100">
                    <div class="text-muted small mb-1"><?= DEPORTES[deporte_clave($deporte)]['emoji'] ?> <?= e(etiqueta_anotaciones($deporte)) ?></div>
                    <div class="fs-2 fw-bold"><?= (int) $totalPuntos ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-tile text-center h-100">
                    <div class="text-muted small mb-1">🟨 <?= e(etiqueta_faltas_leves($deporte)) ?></div>
                    <div class="fs-2 fw-bold"><?= count($amarillas) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-tile text-center h-100">
                    <div class="text-muted small mb-1">🟥 <?= e(etiqueta_faltas_graves($deporte)) ?></div>
                    <div class="fs-2 fw-bold"><?= count($rojas) ?></div>
                </div>
            </div>
            <div class="col-6 col-md-3">
                <div class="stat-tile text-center h-100">
                    <?php if ($cobraMultas): ?>
                    <div class="text-muted small mb-1">💰 Multas</div>
                    <div class="fs-5 fw-bold <?= $debe > 0 ? 'text-danger' : 'text-success' ?>">
                        <?= $debe > 0 ? 'Debe ' . e(sancion_monto_texto($torneo, $debe)) : 'Solvente' ?>
                    </div>
                    <?php else: ?>
                    <div class="text-muted small mb-1">Autogoles</div>
                    <div class="fs-2 fw-bold"><?= (int) $autogoles ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php // ---------- Goles por partido: una fila por encuentro, clicable a la ficha ----------
              // Antes era una fila por gol y en el celular cada una se volvía una tarjeta de
              // cuatro renglones. Ahora: rival, fecha, cuántos y en qué minutos. Con muchos
              // partidos sale con buscador, páginas y orden (tabla-datos.js). ?>
        <?php if (!empty($anotacionesPorPartido)): ?>
        <?php $miEquipo = (int) ($jugador['equipo_id'] ?? 0); ?>
        <div class="card-suave p-4 mb-4">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                <h5 class="mb-0"><?= e(etiqueta_anotaciones($deporte)) ?> de la temporada</h5>
                <span class="small text-muted"><strong class="text-body"><?= $basketball ? (int) $totalPuntos : count($anotaciones) ?></strong> en <?= count($anotacionesPorPartido) ?> partido<?= count($anotacionesPorPartido) === 1 ? '' : 's' ?></span>
            </div>
            <div class="table-responsive">
                <table class="table align-middle mb-0 tabla-goles-jugador" <?= count($anotacionesPorPartido) > 8 ? 'data-tabla-datos data-por-pagina="10"' : '' ?>>
                    <thead>
                        <tr class="small text-muted">
                            <th data-ordenable>Partido</th>
                            <th data-ordenable class="d-none d-sm-table-cell">Fecha</th>
                            <th data-ordenable class="text-center"><?= $basketball ? 'Puntos' : e(etiqueta_anotaciones($deporte)) ?></th>
                            <th class="d-none d-md-table-cell"><?= $basketball ? 'Detalle' : 'Minutos' ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($anotacionesPorPartido as $g): $pa = $g['partido']; ?>
                        <?php
                            $rival = null; $resultado = '';
                            if ($pa) {
                                $esLocal = (int) $pa['equipo_local'] === $miEquipo;
                                $rival = $equiposPorId[(int) ($esLocal ? $pa['equipo_visitante'] : $pa['equipo_local'])] ?? null;
                                $propios = (int) ($esLocal ? $pa['marcador_local'] : $pa['marcador_visitante']);
                                $ajenos = (int) ($esLocal ? $pa['marcador_visitante'] : $pa['marcador_local']);
                                $resultado = $propios . '-' . $ajenos;
                                $claseRes = $propios > $ajenos ? 'res-gano' : ($propios < $ajenos ? 'res-perdio' : 'res-empato');
                            }
                            // Minutos (fútbol) o tipo de canasta (basket): chips cortos.
                            $chips = [];
                            foreach ($g['detalle'] as $a) {
                                $chips[] = $basketball
                                    ? '+' . (int) $a['valor']
                                    : ($a['minuto'] !== null ? (int) $a['minuto'] . "'" : '—')
                                        . (($a['tipo_gol'] ?? '') === 'penal' ? ' (P)' : '');
                            }
                        ?>
                        <tr class="<?= $pa ? 'fila-clicable' : '' ?>" <?= $pa ? 'data-href="' . e(url_copa('partido.php?id=' . (int) $pa['id'])) . '"' : '' ?>>
                            <td data-orden="<?= e($rival['nombre'] ?? '') ?>">
                                <?php if ($pa): ?>
                                <div class="d-flex align-items-center gap-2 min-w-0">
                                    <?= $rival ? logo_equipo($rival, 28) : '' ?>
                                    <div class="min-w-0">
                                        <div class="fw-semibold text-truncate">vs <?= e($rival['nombre'] ?? 'Rival') ?></div>
                                        <div class="small text-muted">
                                            <span class="resultado-chip <?= $claseRes ?>"><?= e($resultado) ?></span>
                                            <span class="d-sm-none ms-1"><?= e(formatear_fecha_corta((string) $pa['fecha'])) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td class="d-none d-sm-table-cell small text-muted" data-orden="<?= $pa ? e((string) $pa['fecha']) : '' ?>"><?= $pa ? e(formatear_fecha_corta((string) $pa['fecha'])) : '—' ?></td>
                            <td class="text-center" data-orden="<?= $basketball ? (int) $g['valor'] : (int) $g['cantidad'] ?>">
                                <span class="goles-partido"><?= icono_balon_img($deporte, 16, 'me-1') ?><?= $basketball ? (int) $g['valor'] : (int) $g['cantidad'] ?></span>
                            </td>
                            <td class="d-none d-md-table-cell">
                                <?php if (array_filter($chips, fn($c) => $c !== '—')): ?>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php foreach ($chips as $chip): ?><span class="minuto-chip"><?= e($chip) ?></span><?php endforeach; ?>
                                </div>
                                <?php else: ?>
                                <span class="small text-muted">Sin minuto registrado</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php // ---------- Multas, con su estado una por una ---------- ?>
        <?php if ($cobraMultas && !empty($multas)): ?>
        <div class="card-suave p-4 mb-4">
            <h5 class="mb-3">Multas</h5>
            <div class="table-responsive">
                <table class="table tabla-posiciones align-middle mb-0" <?= count($multas) > 10 ? 'data-tabla-datos data-por-pagina="10"' : '' ?>>
                    <thead>
                        <tr class="small text-muted">
                            <th>Motivo</th>
                            <th class="text-end">Monto</th>
                            <th>Estado</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($multas as $m): ?>
                        <?php $badge = ['pendiente' => 'text-bg-danger', 'pagada' => 'text-bg-success', 'condonada' => 'text-bg-secondary'][$m['estado']] ?? 'text-bg-secondary'; ?>
                        <tr>
                            <td class="td-equipo" data-label="Motivo">
                                <i class="bi bi-square-fill <?= ($m['tipo'] ?? '') === 'roja' ? 'text-danger' : 'text-warning' ?> me-1"></i>
                                <?= ($m['tipo'] ?? '') === 'roja' ? 'Roja' : 'Amarilla' ?>
                                <?php // La jornada y el rival, no el id interno del partido:
                                      // el jugador que abre esto quiere saber de cuándo le
                                      // viene la multa, y "#52" no se lo dice. ?>
                                <?php $pMulta = $partidosPorId[(int) $m['partido_id']] ?? null; ?>
                                <?php if ($pMulta !== null): ?>
                                <?php
                                $rivalMulta = (int) $pMulta['equipo_local'] === (int) $jugador['equipo_id']
                                    ? ($equiposPorId[(int) $pMulta['equipo_visitante']]['nombre'] ?? '')
                                    : ($equiposPorId[(int) $pMulta['equipo_local']]['nombre'] ?? '');
                                ?>
                                <span class="text-muted small d-block">
                                    Jornada <?= (int) ($pMulta['jornada'] ?? 0) ?>
                                    · <?= e(formatear_fecha_corta((string) $pMulta['fecha'])) ?>
                                    <?= $rivalMulta !== '' ? ' · vs ' . e($rivalMulta) : '' ?>
                                </span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end fw-semibold" data-label="Monto"><?= e(sancion_monto_texto($torneo, (float) $m['monto'])) ?></td>
                            <td data-label="Estado">
                                <span class="badge rounded-pill <?= $badge ?>">
                                    <?= e(['pendiente' => 'Por pagar', 'pagada' => 'Pagada', 'condonada' => 'Condonada'][$m['estado']] ?? $m['estado']) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($anotaciones) && empty($amarillas) && empty($rojas) && empty($multas)): ?>
        <div class="card-suave p-4 text-center text-muted">
            Todavía no registra <?= e(mb_strtolower(etiqueta_anotaciones($deporte))) ?> ni tarjetas en la temporada.
        </div>
        <?php endif; ?>

    </div>
</section>
