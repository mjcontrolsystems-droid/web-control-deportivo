<?php
/**
 * Hoja de carnets. Página suelta (sin el menú del panel) porque su único destino es la
 * impresora: en pantalla se ve igual que en papel, con una barra arriba para imprimir.
 */
$colorA = color_hex_valido($torneo['color_primario'] ?? null, '#7b2ff7');
$colorB = color_hex_valido($torneo['color_secundario'] ?? null, '#ff6b35');
$temporada = trim((string) ($torneo['temporada'] ?? '')) ?: date('Y');
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Carnets · <?= e($equipo['nombre']) ?> — <?= e($torneo['nombre']) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="<?= asset_url('assets/css/style.css') ?>" rel="stylesheet">
    <style>
        :root { --carnet-a: <?= e($colorA) ?>; --carnet-b: <?= e($colorB) ?>; }
    </style>
</head>
<body class="pagina-carnets">
<div class="barra-carnets">
    <a href="<?= e($urlVolver) ?>" class="btn-carnets btn-carnets--claro"><i class="bi bi-arrow-left"></i> Volver a la plantilla</a>
    <div class="barra-carnets__texto">
        <strong><?= count($carnets) ?> carnet<?= count($carnets) === 1 ? '' : 's' ?></strong> · <?= e($equipo['nombre']) ?>
        <span>Tamaño tarjeta (8.5 × 5.4 cm), 8 por hoja carta. Imprime al 100 %, sin "ajustar a la página".</span>
    </div>
    <button type="button" class="btn-carnets" data-imprimir-carnets><i class="bi bi-printer"></i> Imprimir</button>
</div>

<main class="hoja-carnets">
    <?php foreach ($carnets as $j): ?>
    <article class="carnet">
        <header class="carnet__cabeza">
            <?= logo_torneo($torneo, 22) ?>
            <div class="carnet__copa">
                <span class="carnet__copa-nombre"><?= e($torneo['nombre']) ?></span>
                <span class="carnet__copa-temporada">Temporada <?= e($temporada) ?></span>
            </div>
            <span class="carnet__deporte"><?= icono_balon_img($deporte, 16) ?></span>
        </header>
        <div class="carnet__cuerpo">
            <img class="carnet__foto" src="<?= e(url_imagen((string) $j['foto'])) ?>" alt="">
            <div class="carnet__datos">
                <div class="carnet__nombre"><?= e($j['nombre']) ?></div>
                <div class="carnet__dorsal">#<?= e((string) $j['dorsal']) ?><?php if (($j['posicion'] ?? '') !== ''): ?> · <?= e(posicion_label($deporte, $j['posicion'])) ?><?php endif; ?></div>
                <div class="carnet__equipo"><?= logo_equipo($equipo, 16) ?><span><?= e($equipo['nombre']) ?></span></div>
                <div class="carnet__numero"><?= e($j['carnet_numero']) ?></div>
            </div>
            <div class="carnet__qr" data-qr="<?= e(url_copa_de($torneo, 'carnet.php?c=' . $j['carnet_token'])) ?>"></div>
        </div>
        <footer class="carnet__pie">Escanea el código para verificar si está habilitado</footer>
    </article>
    <?php endforeach; ?>
</main>

<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script src="<?= asset_url('assets/js/carnets.js') ?>"></script>
</body>
</html>
