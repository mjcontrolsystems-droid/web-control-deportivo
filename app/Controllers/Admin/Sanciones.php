<?php
declare(strict_types=1);

/**
 * Control de multas por tarjeta: quién debe, cuánto, y el registro de los cobros.
 */

auth_requerir();
$torneo = admin_requerir_torneo_activo();
requerir_permiso('sanciones');

$urlLista = url('admin/sanciones.php');

// Si la liga no cobra multas no hay nada que administrar: la vista muestra un aviso
// que lleva a configurarlas, en vez de una pantalla vacía sin explicación.
$sinTarifas = !torneo_cobra_multas($torneo);

$equipos = equipos_listar($torneo['id']);
$equiposPorId = [];
foreach ($equipos as $eq) {
    $equiposPorId[$eq['id']] = $eq;
}
$jugadores = jugadores_listar($torneo['id']);
$jugadoresPorId = jugadores_por_id($jugadores);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $accion = (string) ($_POST['accion'] ?? '');
    $nota = trim((string) ($_POST['nota'] ?? ''));

    if ($accion === 'cobrar' || $accion === 'condonar' || $accion === 'reabrir') {
        $id = (int) ($_POST['id'] ?? 0);
        $estado = $accion === 'cobrar' ? SANCION_PAGADA : ($accion === 'condonar' ? SANCION_CONDONADA : SANCION_PENDIENTE);
        if (sancion_actualizar_estado($id, $torneo['id'], $estado, $nota)) {
            $etiquetas = ['cobrar' => 'Pago registrado.', 'condonar' => 'Sanción condonada.', 'reabrir' => 'Sanción marcada como pendiente de nuevo.'];
            bitacora_registrar('sancion_' . $accion, 'Sanción #' . $id, $torneo['id']);
            redirigir_con_mensaje($urlLista, 'success', $etiquetas[$accion]);
        }
        redirigir_con_mensaje($urlLista, 'error', 'No se pudo actualizar la sanción.');
    }

    // Anular por apelación: la sanción se borra (esté pendiente, pagada o condonada) y,
    // si se pide, también la tarjeta del partido, para que deje de contar en suspensiones,
    // acumulación de amarillas y en la tabla de disciplina. Condonar no sirve para esto:
    // perdona el dinero pero la tarjeta sigue existiendo.
    if ($accion === 'anular') {
        $id = (int) ($_POST['id'] ?? 0);
        $quitarTarjeta = !empty($_POST['quitar_tarjeta']);
        if ($id <= 0) {
            redirigir_con_mensaje($urlLista, 'error', 'No llegó cuál sanción anular. Recarga la página (Ctrl+F5) y vuelve a intentarlo.');
        }
        $sancion = sancion_obtener($id, (int) $torneo['id']);
        if ($sancion === null) {
            // Lo normal es un doble envío: el primero ya la borró.
            redirigir_con_mensaje($urlLista, 'warning', 'Esa sanción ya estaba anulada.');
        }

        // Primero la tarjeta: si se borrara antes la sanción y esto fallara, quedaría una
        // tarjeta "huérfana" contando para suspensiones sin que nadie lo note.
        $tarjetaQuitada = false;
        if ($quitarTarjeta) {
            $partidoId = (int) $sancion['partido_id'];
            $eventosPartido = db_leer_eventos_partido((int) $torneo['id'], $partidoId);
            $restantes = array_values(array_filter($eventosPartido, fn($ev) => (int) $ev['id'] !== (int) $sancion['evento_id']));
            if (count($restantes) < count($eventosPartido)) {
                if (!db_guardar_eventos_partido((int) $torneo['id'], $partidoId, $restantes)) {
                    redirigir_con_mensaje($urlLista, 'error', 'No se pudo quitar la tarjeta del partido. No se anuló nada.');
                }
                $tarjetaQuitada = true;
            }
        }

        if (!sancion_eliminar($id, (int) $torneo['id'])) {
            redirigir_con_mensaje($urlLista, 'error', 'No se pudo anular la sanción.');
        }

        // En etapa de prueba no se deja rastro en la bitácora. Cuando la opción quede
        // aprobada se agrega aquí bitacora_registrar('sancion_anulada', ...) con el motivo.

        $mensaje = 'Sanción anulada.' . ($tarjetaQuitada ? ' También se quitó la tarjeta del partido.' : '');
        if ($sancion['estado'] === SANCION_PAGADA) {
            $mensaje .= ' Ya estaba pagada: recuerda devolver ' . sancion_monto_texto($torneo, (float) $sancion['monto']) . '.';
        }
        // Se vuelve a la misma pestaña (Por cobrar, Pagadas...) desde donde se anuló.
        $volver = (string) ($_POST['volver_estado'] ?? '');
        $volver = in_array($volver, [SANCION_PENDIENTE, SANCION_PAGADA, SANCION_CONDONADA, 'todas'], true) ? $volver : SANCION_PENDIENTE;
        redirigir_con_mensaje($urlLista . '?estado=' . $volver, 'success', $mensaje);
    }

    // El capitán paga de una vez todas las de su equipo.
    if ($accion === 'cobrar_equipo') {
        $equipoId = (int) ($_POST['equipo_id'] ?? 0);
        $cobradas = sanciones_cobrar_equipo($torneo['id'], $equipoId, $nota);
        $nombreEquipo = $equiposPorId[$equipoId]['nombre'] ?? '';
        bitacora_registrar('sancion_cobro_equipo', "{$nombreEquipo}: {$cobradas} sanciones cobradas", $torneo['id']);
        redirigir_con_mensaje($urlLista, 'success', "Se registraron {$cobradas} pago(s) de {$nombreEquipo}.");
    }
}

$filtroEstado = (string) ($_GET['estado'] ?? SANCION_PENDIENTE);
if (!in_array($filtroEstado, [SANCION_PENDIENTE, SANCION_PAGADA, SANCION_CONDONADA, 'todas'], true)) {
    $filtroEstado = SANCION_PENDIENTE;
}

$sanciones = sanciones_listar($torneo['id'], $filtroEstado === 'todas' ? '' : $filtroEstado);
$resumen = sanciones_resumen($torneo['id']);

// De qué encuentro salió cada tarjeta, dicho como lo diría una persona.
//
// Antes se mostraba el id del partido en la base ("Encuentro #52"): un número interno que
// no coincide con la jornada ni con nada que el organizador reconozca, porque los ids son
// correlativos de todo lo que se creó alguna vez, incluidos los calendarios que se
// borraron y se regeneraron. Cuando un jugador pregunta "¿de cuándo es esa multa?", lo
// que hace falta es la jornada, la fecha y el rival.
$partidosSancion = [];
foreach (partidos_listar($torneo['id']) as $p) {
    $partidosSancion[(int) $p['id']] = $p;
}

// Agrupadas por equipo: así el organizador cobra "por mesa" cuando llega el capitán.
$porEquipo = [];
foreach ($sanciones as $s) {
    $porEquipo[$s['equipo_id']][] = $s;
}

// Cuánto debe cada equipo en total (solo pendientes), para el botón de cobro en lote.
$deudaEquipo = [];
foreach (sanciones_listar($torneo['id'], SANCION_PENDIENTE) as $s) {
    $deudaEquipo[$s['equipo_id']] = ($deudaEquipo[$s['equipo_id']] ?? 0) + $s['monto'];
}

$seccion_activa = 'sanciones';
$titulo_pagina = 'Sanciones y multas';

vista_admin('admin/sanciones', compact(
    'deudaEquipo',
    'equiposPorId',
    'filtroEstado',
    'jugadoresPorId',
    'partidosSancion',
    'porEquipo',
    'resumen',
    'sanciones',
    'seccion_activa',
    'sinTarifas',
    'titulo_pagina',
    'torneo'
));
