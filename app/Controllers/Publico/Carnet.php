<?php
declare(strict_types=1);

/**
 * Verificación del carnet: lo que abre el QR impreso en cada carnet.
 *
 * Pensada para el árbitro o la mesa en la cancha, desde el celular: foto, nombre, equipo
 * y, sobre todo, si el jugador está HABILITADO para el próximo partido de su equipo. No
 * habilitado si fue dado de baja, si su inscripción está pendiente, si está suspendido o
 * si debe una multa que la copa exige pagar antes de jugar.
 *
 * El enlace lleva un código aleatorio (carnet_token), no el id del jugador: así no se
 * pueden recorrer los carnets de todos cambiando un número. Nunca se muestran el
 * documento ni la fecha de nacimiento.
 */

header('X-Robots-Tag: noindex, nofollow');

$torneo = copa_actual();
$jugadoresTodos = jugadores_listar($torneo['id']);
$jugador = jugador_por_carnet_token($jugadoresTodos, (string) ($_GET['c'] ?? ''));
if ($jugador === null) {
    vista_404_copa('Carnet no válido', url_copa('index.php'), 'Ir al inicio de la copa');
}

$equipos = equipos_listar($torneo['id']);
$equipo = db_buscar_por_id($equipos, (int) $jugador['equipo_id']);
$partidos = partidos_listar($torneo['id']);

// El partido que importa es el próximo de su equipo: ahí es donde se va a presentar.
$proximo = null;
foreach ($partidos as $p) {
    $esDelEquipo = (int) $p['equipo_local'] === (int) $jugador['equipo_id'] || (int) $p['equipo_visitante'] === (int) $jugador['equipo_id'];
    if ($esDelEquipo && ($p['estado'] ?? '') !== 'jugado'
        && ($proximo === null || strcmp($p['fecha'] . $p['hora'], $proximo['fecha'] . $proximo['hora']) < 0)) {
        $proximo = $p;
    }
}

$motivos = [];
if (empty($jugador['activo'])) {
    $motivos[] = 'Fue dado de baja de la plantilla.';
}
if (!jugador_inscrito($jugador)) {
    $motivos[] = 'Su inscripción está pendiente de aprobación.';
}
if ($proximo !== null) {
    $suspendidos = disciplina_suspendidos_para_partido($torneo['id'], $proximo, $torneo, $partidos, jugadores_por_id($jugadoresTodos));
    if (isset($suspendidos[(int) $jugador['id']])) {
        $motivos[] = 'Suspendido: ' . $suspendidos[(int) $jugador['id']]['detalle'] . '.';
    }
    if (torneo_cobra_multas($torneo) && torneo_bloquea_morosos($torneo)) {
        $deudas = sanciones_deuda_vigente_para_jornada($torneo['id'], $partidos, (int) ($proximo['jornada'] ?? 0));
        if (isset($deudas[(int) $jugador['id']])) {
            $motivos[] = 'Tiene ' . sancion_monto_texto($torneo, $deudas[(int) $jugador['id']]['total']) . ' en multas pendientes.';
        }
    }
}

$rival = null;
if ($proximo !== null) {
    $idRival = (int) $proximo['equipo_local'] === (int) $jugador['equipo_id'] ? (int) $proximo['equipo_visitante'] : (int) $proximo['equipo_local'];
    $rival = db_buscar_por_id($equipos, $idRival);
}

$titulo_pagina = 'Verificación de carnet — ' . $torneo['nombre'];
$pagina_activa = 'equipos';

vista_publica('publico/carnet', [
    'torneo' => $torneo,
    'jugador' => $jugador,
    'equipo' => $equipo,
    'proximo' => $proximo,
    'rival' => $rival,
    'motivos' => $motivos,
    'deporte' => $torneo['deporte'] ?? null,
    'titulo_pagina' => $titulo_pagina,
    'pagina_activa' => $pagina_activa,
]);
