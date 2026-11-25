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

// --- Limpieza de tarjetas (borrón y cuenta nueva) ---
// No borra tarjetas ni multas: guarda un corte (ver app/Models/DisciplinaCorte.php). Lo
// jugado hasta esa fecha deja de contar para acumular y, si se elige, para suspender.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $urlVolver = url('admin/disciplina.php');
    $accionCorte = (string) ($_POST['accion'] ?? '');

    if ($accionCorte === 'limpiar') {
        $equiposCopa = equipos_listar((int) $torneo['id']);
        $equipoCorte = (int) ($_POST['equipo_id'] ?? 0);
        $equipoElegido = $equipoCorte > 0 ? equipo_buscar($equiposCopa, $equipoCorte) : null;
        // Un equipo que no es de esta copa NO se convierte en "todos": se rechaza.
        if ($equipoCorte > 0 && $equipoElegido === null) {
            redirigir_con_mensaje($urlVolver, 'error', 'Ese equipo no es de esta copa.');
        }
        $equipoCorte = $equipoElegido !== null ? $equipoCorte : null;
        $hastaFecha = (string) ($_POST['hasta_fecha'] ?? '');
        $modoCorte = (string) ($_POST['modo'] ?? 'amarillas');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $hastaFecha) || strtotime($hastaFecha) === false || $hastaFecha > date('Y-m-d')) {
            redirigir_con_mensaje($urlVolver, 'error', 'Elige una fecha válida (hoy o antes).');
        }
        if (!in_array($modoCorte, DISCIPLINA_CORTE_MODOS, true)) {
            redirigir_con_mensaje($urlVolver, 'error', 'Elige qué quieres limpiar.');
        }
        if (!disciplina_corte_crear((int) $torneo['id'], $equipoCorte, $hastaFecha, $modoCorte, isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null)) {
            redirigir_con_mensaje($urlVolver, 'error', 'No se pudo hacer la limpieza.');
        }
        $quien = $equipoElegido !== null ? 'de ' . $equipoElegido['nombre'] : 'de todos los equipos';
        redirigir_con_mensaje($urlVolver, 'success', 'Tarjetas limpiadas ' . $quien . ' hasta el ' . date('d/m/Y', strtotime($hastaFecha)) . '. '
            . ($modoCorte === 'todo' ? 'Las amarillas acumuladas y las suspensiones pendientes quedaron en cero.' : 'Las amarillas acumuladas quedaron en cero; las suspensiones que ya salieron se cumplen.')
            . ' El dinero recaudado no cambió.');
    }

    if ($accionCorte === 'deshacer') {
        if (!disciplina_corte_eliminar((int) ($_POST['id'] ?? 0), (int) $torneo['id'])) {
            redirigir_con_mensaje($urlVolver, 'warning', 'Esa limpieza ya no existe.');
        }
        redirigir_con_mensaje($urlVolver, 'success', 'Limpieza deshecha: las tarjetas de antes vuelven a contar.');
    }
    redirigir_con_mensaje($urlVolver, 'error', 'Acción no válida.');
}
$cortesDisciplina = disciplina_cortes_listar((int) $torneo['id']);

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
    'cortesDisciplina',
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
