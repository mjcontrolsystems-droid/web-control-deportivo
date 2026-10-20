<?php
declare(strict_types=1);

// Catálogo fijo de todas las fases de eliminación directa posibles, de mayor a menor.
// Cada copa elige un subconjunto (torneo['fases_playoff']) al crearse/editarse.
// El tercer lugar va entre la semifinal y la final porque lo juegan los perdedores de la
// semi y suele abrir la jornada de la final, no porque sea una ronda "anterior".
const FASES_PLAYOFF_CATALOGO = ['dieciseisavos', 'octavos', 'cuartos', 'semifinal', 'tercer_lugar', 'final'];

const FASES_LABEL = [
    // La clave sigue siendo 'grupos' porque así está guardada en la base, pero el nombre
    // visible es "temporada regular": nunca hubo división en grupos, siempre fue todos
    // contra todos con una sola tabla.
    'grupos' => 'Temporada regular',
    'dieciseisavos' => 'Dieciseisavos de Final',
    'octavos' => 'Octavos de Final',
    'cuartos' => 'Cuartos de Final',
    'semifinal' => 'Semifinal',
    'tercer_lugar' => 'Tercer lugar',
    'final' => 'Final',
];

/**
 * Calcula la tabla de posiciones a partir de los equipos y los partidos jugados de fase de grupos.
 * Las reglas (si hay empates, cuántos puntos vale cada resultado) vienen del torneo, porque
 * basketball y fútbol se califican distinto.
 *
 * @param array $reglas ['permite_empates' => bool, 'puntos_victoria' => int, 'puntos_empate' => int, 'puntos_derrota' => int]
 * @param array $eventos Solo modo liga: filas de partido_eventos de toda la copa, para sumar
 *   tarjetas por equipo. Se deja vacío (default) en modo copa, donde ni siquiera existen.
 * @return array<int, array> Tabla ordenada, cada fila incluye los datos del equipo + estadísticas + posición.
 */
function calcular_tabla(array $equipos, array $partidos, array $reglas, array $eventos = []): array
{
    // Voleibol: no hay empates y los puntos no son fijos por resultado (FIVB: 3-0 / 2-1
    // según si se ganó en el set decisivo). Ver app/Support/voleibol.php.
    $voleibol = es_voleibol($reglas['deporte'] ?? null);
    $permiteEmpates = !$voleibol && !empty($reglas['permite_empates']);
    $ptsVictoria = (int) ($reglas['puntos_victoria'] ?? 2);
    $ptsEmpate = (int) ($reglas['puntos_empate'] ?? 0);
    $ptsDerrota = (int) ($reglas['puntos_derrota'] ?? 1);

    // Los encuentros de playoffs no cuentan para la tabla de la fase regular
    $partidos = array_filter($partidos, fn($p) => ($p['fase'] ?? 'grupos') === 'grupos');

    $stats = [];
    foreach ($equipos as $equipo) {
        $stats[$equipo['id']] = [
            'equipo' => $equipo,
            'pj' => 0,
            'pg' => 0,
            'pe' => 0,
            'pp' => 0,
            'pf' => 0,
            'pc' => 0,
            'tarjetas_amarillas' => 0,
            'tarjetas_rojas' => 0,
            'racha' => [],
            // Solo voleibol: puntos de tabla acumulados partido a partido y puntos de
            // juego (los del marcador de cada set) para el cociente de puntos.
            'pts_voleibol' => 0,
            'puntos_favor' => 0,
            'puntos_contra' => 0,
        ];
    }

    // Ordenar partidos por fecha para poder construir la racha reciente en orden cronológico
    $jugados = array_filter($partidos, fn($p) => ($p['estado'] ?? '') === 'jugado'
        && $p['marcador_local'] !== null && $p['marcador_visitante'] !== null);
    usort($jugados, fn($a, $b) => strcmp($a['fecha'] . $a['hora'], $b['fecha'] . $b['hora']));

    foreach ($jugados as $partido) {
        $localId = (int) $partido['equipo_local'];
        $visitId = (int) $partido['equipo_visitante'];
        if (!isset($stats[$localId]) || !isset($stats[$visitId])) {
            continue;
        }
        $pl = (int) $partido['marcador_local'];
        $pv = (int) $partido['marcador_visitante'];

        $stats[$localId]['pj']++;
        $stats[$visitId]['pj']++;
        $stats[$localId]['pf'] += $pl;
        $stats[$localId]['pc'] += $pv;
        $stats[$visitId]['pf'] += $pv;
        $stats[$visitId]['pc'] += $pl;

        if ($voleibol && $pl !== $pv) {
            $ganadorId = $pl > $pv ? $localId : $visitId;
            $perdedorId = $pl > $pv ? $visitId : $localId;
            [$ptsGanador, $ptsPerdedor] = voleibol_puntos_tabla(max($pl, $pv), min($pl, $pv), !empty($partido['por_default']));
            $stats[$ganadorId]['pts_voleibol'] += $ptsGanador;
            $stats[$perdedorId]['pts_voleibol'] += $ptsPerdedor;
            // Por default no hubo sets jugados: FIVB los cuenta 25-0 cada uno.
            $sets = !empty($partido['por_default'])
                ? array_fill(0, max($pl, $pv), $pl > $pv ? [25, 0] : [0, 25])
                : voleibol_sets_de_partido($partido);
            [$puntosLocal, $puntosVisita] = voleibol_puntos_totales($sets);
            $stats[$localId]['puntos_favor'] += $puntosLocal;
            $stats[$localId]['puntos_contra'] += $puntosVisita;
            $stats[$visitId]['puntos_favor'] += $puntosVisita;
            $stats[$visitId]['puntos_contra'] += $puntosLocal;
        }

        if ($pl === $pv && $permiteEmpates) {
            $stats[$localId]['pe']++;
            $stats[$visitId]['pe']++;
            $stats[$localId]['racha'][] = 'E';
            $stats[$visitId]['racha'][] = 'E';
        } elseif ($pl > $pv) {
            $stats[$localId]['pg']++;
            $stats[$visitId]['pp']++;
            $stats[$localId]['racha'][] = 'G';
            $stats[$visitId]['racha'][] = 'P';
        } else {
            $stats[$visitId]['pg']++;
            $stats[$localId]['pp']++;
            $stats[$visitId]['racha'][] = 'G';
            $stats[$localId]['racha'][] = 'P';
        }
    }

    if ($eventos) {
        // Solo cuenta tarjetas de partidos de fase de grupos ya jugados (mismo universo que pf/pc).
        $partidosValidos = array_flip(array_map(fn($p) => (int) $p['id'], $jugados));
        foreach ($eventos as $evento) {
            if (!isset($partidosValidos[(int) $evento['partido_id']])) {
                continue;
            }
            $equipoId = (int) $evento['equipo_id'];
            if (!isset($stats[$equipoId])) {
                continue;
            }
            if ($evento['tipo'] === 'amarilla') {
                $stats[$equipoId]['tarjetas_amarillas']++;
            } elseif ($evento['tipo'] === 'roja') {
                $stats[$equipoId]['tarjetas_rojas']++;
            }
        }
    }

    $tabla = [];
    foreach ($stats as $fila) {
        $fila['dif'] = $fila['pf'] - $fila['pc'];
        $fila['pts'] = $voleibol
            ? $fila['pts_voleibol']
            : $fila['pg'] * $ptsVictoria + $fila['pe'] * $ptsEmpate + $fila['pp'] * $ptsDerrota;
        // Cocientes FIVB (a favor / en contra). Sin nada en contra el cociente es "infinito":
        // se guarda como un número enorme para ordenar y la vista lo muestra como MAX.
        $fila['cociente_sets'] = $fila['pc'] > 0 ? round($fila['pf'] / $fila['pc'], 3) : ($fila['pf'] > 0 ? 999.0 : 0.0);
        $fila['cociente_puntos'] = $fila['puntos_contra'] > 0 ? round($fila['puntos_favor'] / $fila['puntos_contra'], 3) : ($fila['puntos_favor'] > 0 ? 999.0 : 0.0);
        $fila['porcentaje'] = $fila['pj'] > 0 ? round(($fila['pg'] / $fila['pj']) * 100) : 0;
        $fila['racha'] = array_slice(array_reverse($fila['racha']), 0, 5);
        $tabla[] = $fila;
    }

    // FIVB: partidos ganados, puntos, cociente de sets y cociente de puntos.
    $ordenVoleibol = fn($a, $b) => [$b['pg'], $b['pts'], $b['cociente_sets'], $b['cociente_puntos'], $a['equipo']['nombre']]
        <=> [$a['pg'], $a['pts'], $a['cociente_sets'], $a['cociente_puntos'], $b['equipo']['nombre']];

    usort($tabla, $voleibol ? $ordenVoleibol : function ($a, $b) {
        if ($a['pts'] !== $b['pts']) {
            return $b['pts'] <=> $a['pts'];
        }
        if ($a['dif'] !== $b['dif']) {
            return $b['dif'] <=> $a['dif'];
        }
        if ($a['pf'] !== $b['pf']) {
            return $b['pf'] <=> $a['pf'];
        }
        return strcmp($a['equipo']['nombre'], $b['equipo']['nombre']);
    });

    foreach ($tabla as $i => &$fila) {
        $fila['posicion'] = $i + 1;
    }
    unset($fila);

    return $tabla;
}

/**
 * Devuelve los próximos N partidos programados (por fecha) y los últimos N resultados jugados.
 */
function proximos_partidos(array $partidos, int $limite = 4): array
{
    $prog = array_filter($partidos, fn($p) => ($p['estado'] ?? '') === 'programado');
    usort($prog, fn($a, $b) => strcmp($a['fecha'] . $a['hora'], $b['fecha'] . $b['hora']));
    return array_slice($prog, 0, $limite);
}

function ultimos_resultados(array $partidos, int $limite = 4): array
{
    $jugados = array_filter($partidos, fn($p) => ($p['estado'] ?? '') === 'jugado');
    usort($jugados, fn($a, $b) => strcmp($b['fecha'] . $b['hora'], $a['fecha'] . $a['hora']));
    return array_slice($jugados, 0, $limite);
}

function partidos_por_jornada(array $partidos): array
{
    $jornadas = [];
    foreach ($partidos as $p) {
        if (($p['fase'] ?? 'grupos') !== 'grupos') {
            continue;
        }
        $j = (int) $p['jornada'];
        $jornadas[$j][] = $p;
    }
    ksort($jornadas);
    foreach ($jornadas as &$lista) {
        usort($lista, fn($a, $b) => strcmp($a['fecha'] . $a['hora'], $b['fecha'] . $b['hora']));
    }
    unset($lista);
    return $jornadas;
}

/**
 * Agrupa los encuentros de eliminación directa por fase, cada uno ordenado por fecha.
 * Las fases que la copa tiene habilitadas (aunque sin encuentros cargados) igual aparecen
 * como lista vacía, para que la web pueda mostrar el espacio reservado.
 *
 * @param array $fasesHabilitadas Las fases que esta copa usa, ej. ['cuartos','semifinal','final']
 */
function partidos_playoffs_por_fase(array $partidos, array $fasesHabilitadas): array
{
    $porFase = array_fill_keys($fasesHabilitadas, []);
    foreach ($partidos as $p) {
        $fase = $p['fase'] ?? 'grupos';
        if (isset($porFase[$fase])) {
            $porFase[$fase][] = $p;
        }
    }
    foreach ($porFase as &$lista) {
        usort($lista, fn($a, $b) => strcmp($a['fecha'] . $a['hora'], $b['fecha'] . $b['hora']));
    }
    unset($lista);
    return $porFase;
}
