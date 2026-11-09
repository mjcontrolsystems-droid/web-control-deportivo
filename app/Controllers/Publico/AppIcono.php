<?php
declare(strict_types=1);

/**
 * Ícono y manifest de "Agregar a la pantalla de inicio" para el sitio de UNA copa.
 *
 *   /<slug>/icono.php?t=180          → ícono para iPhone (apple-touch-icon)
 *   /<slug>/icono.php?t=512&m=1      → ícono adaptable de Android (maskable)
 *   /<slug>/manifest.php             → manifest de la copa (nombre, colores, íconos)
 *
 * Así, quien agrega la copa a su teléfono ve el ESCUDO de la copa como app, con su
 * nombre. Si la copa no tiene escudo (o el servidor no puede procesar imágenes) se usan
 * los íconos de la plataforma, que son archivos fijos en public/assets/img/app/.
 */

$torneo = copa_actual();
$esManifest = str_ends_with((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), 'manifest.php');

if ($esManifest) {
    $version = rawurlencode((string) ($torneo['logo'] ?? '0'));
    $base = url($torneo['slug'] . '/');   // con slug explícito, igual que etiquetas_icono_app()
    $icono = fn(int $t, bool $m = false) => $base . 'icono.php?t=' . $t . ($m ? '&m=1' : '') . '&v=' . $version;
    $nombre = trim((string) $torneo['nombre']);
    $manifest = [
        'name' => $nombre,
        // El nombre que sale debajo del ícono. Se manda completo (hasta 30): el teléfono
        // lo recorta solo con "…" según el espacio, que se ve mejor que cortarlo aquí.
        'short_name' => mb_substr($nombre, 0, 30),
        'description' => trim((string) ($torneo['descripcion'] ?? '')) ?: 'Tabla de posiciones, calendario y resultados.',
        'start_url' => $base,
        'scope' => $base,
        'display' => 'standalone',
        'background_color' => '#ffffff',
        'theme_color' => color_hex_valido($torneo['color_primario'] ?? null, '#7b2ff7'),
        'lang' => 'es',
        'icons' => [
            ['src' => $icono(192), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $icono(512), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => $icono(512, true), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
    ];
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

// --- Ícono ---
$tam = (int) ($_GET['t'] ?? 192);
$tam = in_array($tam, [180, 192, 512], true) ? $tam : 192;
$maskable = !empty($_GET['m']);

$png = null;
$escudo = !empty($torneo['logo']) ? imagen_datos((string) $torneo['logo']) : null;
if ($escudo !== null) {
    $png = icono_app_png($escudo, $tam, $tam === 180 || $maskable, $maskable ? 0.66 : ($tam === 180 ? 0.80 : 0.92));
}

if ($png === null) {
    // Sin escudo o sin GD: el ícono de la plataforma.
    $archivo = match (true) {
        $tam === 180 => 'apple-touch-icon.png',
        $maskable => 'icono-maskable-512.png',
        $tam === 512 => 'icono-512.png',
        default => 'icono-192.png',
    };
    header('Location: ' . url('assets/img/app/' . $archivo), true, 302);
    exit;
}

header('Content-Type: image/png');
// La URL lleva ?v=<id del escudo>: si cambian el escudo cambia la URL, así que se puede
// guardar en caché mucho tiempo.
header('Cache-Control: public, max-age=2592000');
header('Content-Length: ' . strlen($png));
echo $png;
exit;
