<?php
declare(strict_types=1);

/**
 * Quién lleva más tarjetas.
 *
 * Existe aparte de Sanciones a propósito: Sanciones es el cobro de las multas y solo
 * aparece si la liga cobra. Esta pantalla es la lectura disciplinaria de la temporada, y
 * la necesita cualquier organizador — cobre o no cobre.
 *
 * Lo que resuelve: hasta ahora las tarjetas solo se veían de a una, dentro de la ficha de
 * cada partido. Para saber quién se está pasando había que ir encuentro por encuentro.
 */

auth_requerir();
$torneo = admin_requerir_torneo_activo();
requerir_permiso('sanciones');

$equipos = equipos_listar($torneo['id']);
$equiposPorId = [];
foreach ($equipos as $eq) {
    $equiposPorId[(int) $eq['id']] = $eq;
}

$partidos = partidos_listar($torneo['id']);
$jugadores = jugadores_listar($torneo['id']);
$jugadoresPorId = jugadores_por_id($jugadores);
$eventos = eventos_de_torneo($torneo['id']);

$ranking = disciplina_ranking_desde_eventos($eventos, $jugadores, $equiposPorId, $torneo, $partidos);
$rankingEquipos = disciplina_ranking_equipos($ranking, $equiposPorId);

// Filtro por equipo: con 240 jugadores, mirar solo una plantilla es lo que se hace cuando
// hay que hablar con un delegado.
$equipoFiltro = (int) ($_GET['equipo_id'] ?? 0);
if ($equipoFiltro > 0 && isset($equiposPorId[$equipoFiltro])) {
    $ranking = array_values(array_filter($ranking, fn($f) => (int) $f['jugador']['equipo_id'] === $equipoFiltro));
} else {
    $equipoFiltro = 0;
}

// Los que no pueden jugar su próximo encuentro. Se resuelve por equipo porque la ventana
// de castigo se cuenta sobre el calendario de CADA equipo, no sobre el de la copa.
$castigos = torneo_aplica_suspensiones($torneo)
    ? disciplina_castigos_desde_eventos($eventos, $torneo, $partidos)
    : [];
$suspendidosAhora = [];
if (!empty($castigos)) {
    foreach ($equipos as $eq) {
        $proximo = null;
        foreach ($partidos as $p) {
            $esSuyo = (int) $p['equipo_local'] === (int) $eq['id'] || (int) $p['equipo_visitante'] === (int) $eq['id'];
            if (!$esSuyo || ($p['estado'] ?? '') === 'jugado') {
                continue;
            }
            if ($proximo === null || strcmp((string) $p['fecha'] . $p['hora'], (string) $proximo['fecha'] . $proximo['hora']) < 0) {
                $proximo = $p;
            }
        }
        if ($proximo === null) {
            continue;   // ese equipo ya terminó su calendario
        }
        foreach (disciplina_suspendidos_desde_castigos($castigos, $proximo, $partidos, $jugadoresPorId) as $jid => $info) {
            $suspendidosAhora[$jid] = $info;
        }
    }
}

// Totales de arriba.
$totalAmarillas = 0;
$totalRojas = 0;
foreach ($rankingEquipos as $fila) {
    $totalAmarillas += $fila['amarillas'];
    $totalRojas += $fila['rojas'];
}
$alBorde = count(array_filter($ranking, fn($f) => !empty($f['acumulacion']['al_borde'])));

$seccion_activa = 'disciplina';
$titulo_pagina = 'Disciplina';

vista_admin('admin/disciplina', compact(
    'alBorde',
    'equipoFiltro',
    'equipos',
    'equiposPorId',
    'ranking',
    'rankingEquipos',
    'seccion_activa',
    'suspendidosAhora',
    'titulo_pagina',
    'torneo',
    'totalAmarillas',
    'totalRojas'
));
