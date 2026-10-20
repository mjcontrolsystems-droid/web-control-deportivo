<?php $habilitado = empty($motivos); ?>
<header class="hero-copa" style="padding-bottom:2rem;">
    <div class="container text-center">
        <p class="kicker mb-2"><i class="bi bi-person-vcard me-1"></i>Verificación de carnet</p>
        <h1 class="text-white mb-0 h2"><?= e($torneo['nombre']) ?></h1>
    </div>
</header>

<section class="seccion pt-4">
    <div class="container verificacion-carnet text-center">
        <div class="card-suave p-4">
            <?php if (!empty($jugador['foto'])): ?>
            <img class="verificacion-carnet__foto mb-3" src="<?= e(url_imagen((string) $jugador['foto'])) ?>" alt="Foto de <?= e($jugador['nombre']) ?>">
            <?php else: ?>
            <div class="mb-3 d-flex justify-content-center"><?= foto_jugador($jugador, 120) ?></div>
            <?php endif; ?>
            <h2 class="h4 mb-1"><?= e($jugador['nombre']) ?></h2>
            <p class="text-muted mb-2">#<?= e((string) $jugador['dorsal']) ?><?php if (($jugador['posicion'] ?? '') !== ''): ?> · <?= e(posicion_label($deporte, $jugador['posicion'])) ?><?php endif; ?></p>
            <?php if ($equipo !== null): ?>
            <p class="d-flex align-items-center justify-content-center gap-2 fw-semibold mb-1"><?= logo_equipo($equipo, 28) ?><?= e($equipo['nombre']) ?></p>
            <?php endif; ?>
            <p class="small text-muted mb-3">Carnet <strong class="font-monospace"><?= e($jugador['carnet_numero'] ?? '') ?></strong></p>

            <div class="verificacion-carnet__estado <?= $habilitado ? 'verificacion-carnet__estado--ok' : 'verificacion-carnet__estado--no' ?>">
                <?php if ($habilitado): ?>
                <i class="bi bi-check-circle-fill me-1"></i>HABILITADO PARA JUGAR
                <?php else: ?>
                <i class="bi bi-x-octagon-fill me-1"></i>NO HABILITADO
                <ul class="text-start small fw-normal mb-0 mt-2 ps-3">
                    <?php foreach ($motivos as $motivo): ?>
                    <li><?= e($motivo) ?></li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </div>

            <?php if ($proximo !== null && $rival !== null): ?>
            <p class="small text-muted mt-3 mb-0">
                Próximo partido: vs <?= e($rival['nombre']) ?> · <?= e(formatear_fecha_larga((string) $proximo['fecha'])) ?><?= ($proximo['hora'] ?? '') !== '' ? ' · ' . e((string) $proximo['hora']) : '' ?>
            </p>
            <?php endif; ?>
            <p class="small text-muted mt-2 mb-0">Consultado el <?= e(date('d/m/Y H:i')) ?>. La información se actualiza en tiempo real.</p>
        </div>
    </div>
</section>
