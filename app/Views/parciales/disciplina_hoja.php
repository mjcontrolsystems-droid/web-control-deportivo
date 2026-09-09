<?php
/**
 * Reporte de tarjetas para imprimir o archivar.
 *
 * Es el papel que se lleva a una reunión de delegados: cuando alguien reclama por qué su
 * jugador está suspendido, o cuando hay que mostrar que un equipo acumula el doble de
 * tarjetas que el resto, una pantalla no sirve — hace falta algo que se entregue y que
 * tenga fecha.
 *
 * Se arma con los mismos datos de la pantalla, incluido el filtro por equipo: si se está
 * viendo un solo equipo, el reporte es de ese equipo.
 */
$etAmarillaHoja = etiqueta_ta($torneo['deporte'] ?? null);
$etRojaHoja = etiqueta_tr($torneo['deporte'] ?? null);
$equipoDelReporte = $equipoFiltro > 0 ? ($equiposPorId[$equipoFiltro] ?? null) : null;
?>
<div class="ficha-titulo">
    <h2>Reporte de tarjetas<?= $equipoDelReporte !== null ? ' — ' . e($equipoDelReporte['nombre']) : '' ?></h2>
    <p>
        <?= e($torneo['nombre']) ?><?= !empty($torneo['temporada']) ? ' · Temporada ' . e($torneo['temporada']) : '' ?>
        · <?= (int) $totalAmarillas ?> <?= e(mb_strtolower($etAmarillaHoja)) ?>s y <?= (int) $totalRojas ?> <?= e(mb_strtolower($etRojaHoja)) ?>s en lo que va de la competencia
    </p>
</div>

<h3>Jugadores</h3>

<?php if (empty($ranking)): ?>
<p style="font-size:12px;">No hay tarjetas registradas<?= $equipoDelReporte !== null ? ' para este equipo' : '' ?>.</p>
<?php else: ?>
<table class="ficha-tabla">
    <thead>
        <tr>
            <th style="width:8%;">#</th>
            <th>Jugador</th>
            <?php if ($equipoDelReporte === null): ?><th style="width:22%;">Equipo</th><?php endif; ?>
            <th style="width:8%;"><?= e($etAmarillaHoja) ?></th>
            <th style="width:8%;"><?= e($etRojaHoja) ?></th>
            <th style="width:8%;">Total</th>
            <th style="width:24%;">Situación</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($ranking as $f): $jid = (int) $f['jugador']['id']; ?>
        <tr>
            <td><strong><?= e($f['jugador']['dorsal']) ?></strong></td>
            <td><?= e($f['jugador']['nombre']) ?></td>
            <?php if ($equipoDelReporte === null): ?><td><?= e($f['equipo']['nombre'] ?? '') ?></td><?php endif; ?>
            <td style="text-align:center;"><?= (int) $f['amarillas'] ?: '' ?></td>
            <td style="text-align:center;"><?= (int) $f['rojas'] ?: '' ?></td>
            <td style="text-align:center;"><strong><?= (int) $f['total'] ?></strong></td>
            <td style="font-size:11px;">
                <?php // Lo mismo que en pantalla y en el mismo orden de urgencia, para que
                      // el papel y la app nunca digan cosas distintas. ?>
                <?php if (isset($suspendidosAhora[$jid])): ?>
                    <strong>NO JUEGA</strong> — <?= e((string) ($suspendidosAhora[$jid]['detalle'] ?? 'suspendido')) ?>
                <?php elseif (!empty($f['acumulacion']['al_borde'])): ?>
                    A una <?= e(mb_strtolower($etAmarillaHoja)) ?> de la suspensión
                <?php elseif (!empty($f['acumulacion']) && $f['acumulacion']['hacia_suspension'] > 0): ?>
                    <?= (int) $f['acumulacion']['hacia_suspension'] ?> de <?= (int) torneo_amarillas_para_suspension($torneo) ?> acumuladas
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<?php // El resumen por equipo solo tiene sentido en el reporte general: filtrado por un
      // equipo sería una tabla de una sola fila repitiendo lo de arriba. ?>
<?php if ($equipoDelReporte === null && !empty($rankingEquipos)): ?>
<h3>Por equipo</h3>
<table class="ficha-tabla ficha-tabla--junta">
    <thead>
        <tr>
            <th>Equipo</th>
            <th style="width:14%;"><?= e($etAmarillaHoja) ?></th>
            <th style="width:14%;"><?= e($etRojaHoja) ?></th>
            <th style="width:14%;">Total</th>
            <th style="width:20%;">Jugadores</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rankingEquipos as $f): ?>
        <tr>
            <td><?= e($f['equipo']['nombre']) ?></td>
            <td style="text-align:center;"><?= (int) $f['amarillas'] ?></td>
            <td style="text-align:center;"><?= (int) $f['rojas'] ?></td>
            <td style="text-align:center;"><strong><?= (int) $f['total'] ?></strong></td>
            <td style="text-align:center;"><?= (int) $f['jugadores'] ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<div class="ficha-pie">
    <p>
        Ordenado por total de tarjetas; se desempata por <?= e(mb_strtolower($etRojaHoja)) ?>s.
        La suspensión por acumulación es cada <?= (int) torneo_amarillas_para_suspension($torneo) ?>
        <?= e(mb_strtolower($etAmarillaHoja)) ?>s.
    </p>
    <p>Generado el <?= e(date('d/m/Y H:i')) ?> · <?= e($torneo['nombre']) ?></p>
    <p>MJ Control Systems · Plataformas web inteligentes, control total de tu negocio.</p>
</div>
