<?php
declare(strict_types=1);

/**
 * Calendario imprimible de la copa o liga: una hoja limpia con las jornadas y sus
 * encuentros, lista para imprimir o guardar como PDF y repartir a los equipos.
 *
 * Parámetros (todos opcionales, se eligen en la pantalla de opciones):
 *   alcance   'todo'     -> temporada regular + todas las fases de eliminación
 *             'grupos'   -> solo la temporada regular
 *             'jornada'  -> un rango de jornadas (con ?desde=N&hasta=M). Para una sola,
 *                           desde = hasta. Se sigue aceptando ?jornada=N de los enlaces viejos.
 *             'fase'     -> una sola fase de eliminación (con ?fase=cuartos, etc.)
 *   marcadores '1' incluye el resultado de los ya jugados, '0' solo la programación
 *   imprimir   '1' abre el diálogo de impresión al cargar
 */

$torneo = copa_actual();

$equipos = equipos_listar($torneo['id']);
$partidos = partidos_listar($torneo['id']);
$equiposPorId = [];
foreach ($equipos as $eq) {
    $equiposPorId[$eq['id']] = $eq;
}

$jornadas = partidos_por_jornada($partidos);
$esLiga = torneo_es_liga($torneo);
// Las fases que se están jugando: si al armar el cuadro se eligió otro tamaño (8 → cuartos),
// esa ronda también aparece aunque no estuviera en la configuración de la copa.
$fasesTorneo = eliminacion_fases_en_juego($torneo, $partidos);
$playoffsPorFase = partidos_playoffs_por_fase($partidos, $fasesTorneo);

$alcance = (string) ($_GET['alcance'] ?? '');
if (!in_array($alcance, ['todo', 'grupos', 'jornada', 'fase'], true)) {
    $alcance = '';   // sin alcance elegido se muestra la pantalla de opciones
}

// Con marcadores por defecto: el uso más común es imprimir el rol ya avanzada la
// temporada. Se puede desmarcar para repartir solo la programación.
$conMarcadores = ($_GET['marcadores'] ?? '1') === '1';

// Rango de jornadas. Solo se aceptan números de jornadas que existen: lo que venga en la
// URL se fuerza a entero y se acota al rango real, así un valor manipulado no rompe nada.
$numerosJornada = array_map('intval', array_keys($jornadas));
$primeraJornada = $numerosJornada ? min($numerosJornada) : 0;
$ultimaJornada = $numerosJornada ? max($numerosJornada) : 0;
$jornadaVieja = isset($_GET['jornada']) ? (int) $_GET['jornada'] : 0;   // enlaces anteriores
$jornadaDesde = isset($_GET['desde']) ? (int) $_GET['desde'] : ($jornadaVieja ?: $primeraJornada);
$jornadaHasta = isset($_GET['hasta']) ? (int) $_GET['hasta'] : ($jornadaVieja ?: $ultimaJornada);
$jornadaDesde = max($primeraJornada, min($ultimaJornada, $jornadaDesde));
$jornadaHasta = max($primeraJornada, min($ultimaJornada, $jornadaHasta));
if ($jornadaDesde > $jornadaHasta) {
    [$jornadaDesde, $jornadaHasta] = [$jornadaHasta, $jornadaDesde];   // si las eligió al revés
}
$faseElegida = (string) ($_GET['fase'] ?? '');
if (!in_array($faseElegida, $fasesTorneo, true)) {
    $faseElegida = '';
}

/**
 * Bloques a imprimir: cada uno con su título y su lista de encuentros ya ordenada.
 * Se arma aquí (y no en la vista) para que la plantilla solo pinte.
 */
$bloques = [];

$agregarJornadas = function (array $soloJornada = []) use ($jornadas, &$bloques): void {
    foreach ($jornadas as $numero => $lista) {
        if (!empty($soloJornada) && !in_array($numero, $soloJornada, true)) {
            continue;
        }
        usort($lista, fn($a, $b) => strcmp($a['fecha'] . $a['hora'], $b['fecha'] . $b['hora']));
        $bloques[] = ['titulo' => 'Jornada ' . $numero, 'partidos' => $lista];
    }
};

$agregarFases = function (array $soloFase = []) use ($playoffsPorFase, &$bloques): void {
    foreach ($playoffsPorFase as $fase => $lista) {
        if (empty($lista) || (!empty($soloFase) && !in_array($fase, $soloFase, true))) {
            continue;
        }
        usort($lista, fn($a, $b) => strcmp($a['fecha'] . $a['hora'], $b['fecha'] . $b['hora']));
        $bloques[] = ['titulo' => FASES_LABEL[$fase] ?? ucfirst($fase), 'partidos' => $lista];
    }
};

if ($alcance === 'todo') {
    $agregarJornadas();
    if (!$esLiga) {
        $agregarFases();
    }
} elseif ($alcance === 'grupos') {
    $agregarJornadas();
} elseif ($alcance === 'jornada' && $jornadaDesde > 0) {
    $agregarJornadas(array_values(array_filter($numerosJornada, fn($n) => $n >= $jornadaDesde && $n <= $jornadaHasta)));
} elseif ($alcance === 'fase' && $faseElegida !== '') {
    $agregarFases([$faseElegida]);
}

// Subtítulo de la hoja impresa, para que quien la reciba sepa qué jornadas trae.
$subtituloHoja = '';
if ($alcance === 'jornada' && $jornadaDesde > 0) {
    $subtituloHoja = $jornadaDesde === $jornadaHasta
        ? 'Jornada ' . $jornadaDesde
        : 'Jornadas ' . $jornadaDesde . ' a ' . $jornadaHasta;
}

$totalEncuentros = array_sum(array_map(fn($b) => count($b['partidos']), $bloques));

$titulo_pagina = 'Calendario para imprimir — ' . $torneo['nombre'];
$pagina_activa = 'calendario';

vista_publica('publico/calendario_imprimir', compact(
    'alcance',
    'bloques',
    'conMarcadores',
    'equiposPorId',
    'esLiga',
    'faseElegida',
    'fasesTorneo',
    'jornadaDesde',
    'jornadaHasta',
    'jornadas',
    'pagina_activa',
    'playoffsPorFase',
    'subtituloHoja',
    'titulo_pagina',
    'torneo',
    'totalEncuentros'
));
