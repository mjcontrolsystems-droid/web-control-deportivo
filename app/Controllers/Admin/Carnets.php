<?php
declare(strict_types=1);

/**
 * Carnets de los jugadores de un equipo, listos para imprimir.
 *
 * Tamaño tarjeta de crédito (85.6 × 54 mm), ocho por hoja carta para recortar y enmicar.
 * Cada carnet lleva un QR que abre la verificación pública (carnet.php): ahí el árbitro
 * ve la foto y si el jugador está habilitado para jugar ese día.
 *
 * Solo salen los inscritos, activos, con foto y con número asignado (lo asigna la
 * aprobación o el botón "Carnets" de la plantilla). El documento y la fecha de nacimiento
 * no van en el carnet: son datos privados.
 */

auth_requerir();
$torneo = admin_requerir_torneo_activo();
requerir_permiso('jugadores');

$equipoId = (int) ($_GET['equipo_id'] ?? 0);
$equipo = db_buscar_por_id(equipos_listar($torneo['id']), $equipoId);
if ($equipo === null) {
    http_response_code(404);
    exit('Equipo no encontrado.');
}
// El capitán solo imprime los de su equipo.
requerir_equipo_propio($equipoId, $torneo);

$soloId = (int) ($_GET['jugador_id'] ?? 0);
$carnets = array_values(array_filter(
    jugadores_listar($torneo['id']),
    fn($j) => (int) $j['equipo_id'] === $equipoId
        && ($soloId === 0 || (int) $j['id'] === $soloId)
        && jugador_habilitado_plantilla($j)
        && !empty($j['foto'])
        && ($j['carnet_numero'] ?? '') !== ''
        && ($j['carnet_token'] ?? '') !== ''
));
usort($carnets, fn($a, $b) => (int) $a['dorsal'] <=> (int) $b['dorsal']);

if (empty($carnets)) {
    redirigir_con_mensaje(url('admin/jugadores.php?equipo_id=' . $equipoId), 'error', 'No hay carnets listos: el jugador tiene que estar inscrito, activo y con foto. Usa el botón "Carnets" de la plantilla.');
}

vista('admin/carnets', [
    'torneo' => $torneo,
    'equipo' => $equipo,
    'carnets' => $carnets,
    'deporte' => $torneo['deporte'] ?? null,
    'urlVolver' => url('admin/jugadores.php?equipo_id=' . $equipoId),
]);
