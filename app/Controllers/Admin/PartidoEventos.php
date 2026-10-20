<?php
declare(strict_types=1);

auth_requerir();
$torneo = admin_requerir_torneo_activo();
requerir_permiso('partido_capturar');

$partidoId = (int) ($_GET['partido_id'] ?? $_POST['partido_id'] ?? 0);
$partidos = partidos_listar($torneo['id']);
$partido = db_buscar_por_id($partidos, $partidoId);
if ($partido === null) {
    http_response_code(404);
    exit('Encuentro no encontrado.');
}

$equipos = equipos_listar($torneo['id']);
$equiposPorId = [];
foreach ($equipos as $eq) { $equiposPorId[$eq['id']] = $eq; }
$equipoLocal = $equiposPorId[(int) $partido['equipo_local']] ?? null;
$equipoVisitante = $equiposPorId[(int) $partido['equipo_visitante']] ?? null;
$equiposDelPartido = array_filter([(int) $partido['equipo_local'], (int) $partido['equipo_visitante']]);

$jugadoresTodos = jugadores_listar($torneo['id']);
$jugadoresPorEquipo = jugadores_por_equipo($jugadoresTodos);
$jugadoresPorId = jugadores_por_id($jugadoresTodos);
$etJugador = forma_genero($torneo['genero'] ?? null, 'Jugador', 'Jugadora');
$deporte = $torneo['deporte'] ?? null;
$basketball = es_basketball($deporte);

// Cuántos jugadores pone cada equipo en la cancha según la modalidad de la copa (5, 7 u
// 11): es el tope de titulares que admite la alineación de este encuentro.
$jugadoresEnCancha = torneo_jugadores_en_cancha($torneo);

$urlLista = url('admin/partido_eventos.php?partido_id=' . $partidoId);

// Regla de integridad (aplica a fútbol y basketball por igual): un encuentro marcado como
// JUGADO tiene su resultado en firme — alimenta la tabla de posiciones y pudo haberse
// publicado/compartido. Sus eventos, cronómetro y fecha quedan bloqueados; solo se pueden
// tocar reabriéndolo explícitamente para corrección (botón con confirmación + bitácora).
$resultadoBloqueado = ($partido['estado'] ?? '') === 'jugado';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $accion = (string) ($_POST['accion'] ?? '');

    // Reabrir para corrección: vuelve el encuentro a "programado" (sale de la tabla hasta
    // volver a marcarlo jugado) y desbloquea la ficha. Queda registrado en la bitácora.
    if ($accion === 'reabrir_correccion') {
        foreach ($partidos as &$p) {
            if ((int) $p['id'] === $partidoId) {
                $p['estado'] = 'programado';
            }
        }
        unset($p);
        partidos_guardar_todos($partidos, $torneo['id']);
        bitacora_registrar('partido_reabierto', 'Encuentro #' . $partidoId . ' reabierto para corrección de resultado', $torneo['id']);
        redirigir_con_mensaje($urlLista, 'success', 'Encuentro reabierto para corrección. Cuando termines, márcalo como jugado de nuevo para que vuelva a contar en la tabla.');
    }

    // Triunfo por default (W.O.), desde la misma ficha: es aquí donde se descubre que un
    // equipo no llegó, no en el formulario de edición. Fija el marcador reglamentario
    // (3-0 fútbol, 20-0 basketball), marca jugado y no asigna goles a nadie. Decidir un
    // W.O. es de asistente para arriba, no de mesa.
    if ($accion === 'marcar_default') {
        requerir_permiso('partidos_editar');
        $lado = (string) ($_POST['lado'] ?? '');
        if (!in_array($lado, ['local', 'visitante'], true)) {
            redirigir_con_mensaje($urlLista,'error', 'Indica qué equipo gana por default.');
        }

        [$ganador, $perdedor] = marcador_por_default($torneo['deporte'] ?? null, $torneo);
        foreach ($partidos as &$p) {
            if ((int) $p['id'] === $partidoId) {
                $p['por_default'] = true;
                $p['estado'] = 'jugado';
                $p['marcador_local'] = $lado === 'local' ? $ganador : $perdedor;
                $p['marcador_visitante'] = $lado === 'visitante' ? $ganador : $perdedor;
            }
        }
        unset($p);
        partidos_guardar_todos($partidos, $torneo['id']);
        bitacora_registrar('partido_default', 'Encuentro #' . $partidoId . ' ganado por default (' . $lado . ')', $torneo['id']);
        redirigir_con_mensaje($urlLista,'success', "Triunfo por default registrado: {$ganador}-{$perdedor}. No se asignan goles a nadie y el encuentro no cuenta para la portería menos vencida.");
    }

    // Cualquier otra acción de escritura sobre un encuentro con resultado en firme se
    // rechaza (protege contra pestañas viejas o envíos directos del formulario).
    if ($resultadoBloqueado) {
        redirigir_con_mensaje($urlLista, 'error', 'Este encuentro ya está finalizado y su resultado quedó en firme. Usa "Reabrir para corrección" si hubo un error.');
    }

    // El partido se está jugando/registrando antes de la fecha programada: el modal de
    // confirmación ofrece adelantar la fecha del encuentro a hoy sin salir de la ficha.
    if ($accion === 'actualizar_fecha_hoy') {
        foreach ($partidos as &$p) {
            if ((int) $p['id'] === $partidoId) {
                $p['fecha'] = date('Y-m-d');
            }
        }
        unset($p);
        partidos_guardar_todos($partidos, $torneo['id']);
        bitacora_registrar('fecha_adelantada', 'Encuentro #' . $partidoId . ' adelantado a ' . date('Y-m-d'), $torneo['id']);
        redirigir_con_mensaje($urlLista, 'success', 'Fecha del encuentro actualizada a hoy.');
    }

    // Voleibol: el resultado se carga set por set ("25-20", "22-25"...). Se valida contra
    // las reglas FIVB de la modalidad (a 25 o 21, el decisivo a 15, siempre por 2) y el
    // marcador del partido pasa a ser los sets ganados. "Guardar y finalizar" además lo da
    // por jugado, pero solo si alguien ya llegó a los sets que hacen falta para ganar.
    if ($accion === 'guardar_sets') {
        if (!es_voleibol($deporte)) {
            redirigir_con_mensaje($urlLista, 'error', 'Los sets solo aplican a copas de voleibol.');
        }
        $maximos = voleibol_sets_maximos($torneo);
        $locales = (array) ($_POST['set_local'] ?? []);
        $visitas = (array) ($_POST['set_visitante'] ?? []);
        $sets = [];
        $huboVacio = false;
        for ($i = 0; $i < $maximos; $i++) {
            $a = trim((string) ($locales[$i] ?? ''));
            $b = trim((string) ($visitas[$i] ?? ''));
            $numero = $i + 1;
            if ($a === '' && $b === '') {
                $huboVacio = true;
                continue;
            }
            if ($huboVacio) {
                redirigir_con_mensaje($urlLista, 'error', "Hay un set vacío antes del set {$numero}: los sets se cargan en orden.");
            }
            if (!ctype_digit($a) || !ctype_digit($b) || (int) $a > 99 || (int) $b > 99) {
                redirigir_con_mensaje($urlLista, 'error', "Completa los puntos de los dos equipos en el set {$numero}.");
            }
            $sets[] = [(int) $a, (int) $b];
        }

        $revision = voleibol_validar_sets($sets, $torneo);
        if (!empty($revision['errores'])) {
            redirigir_con_mensaje($urlLista, 'error', $revision['errores'][0]);
        }
        $finalizar = !empty($_POST['finalizar']);
        if ($finalizar && !$revision['terminado']) {
            redirigir_con_mensaje($urlLista, 'error', 'Todavía no hay ganador: el partido se gana con ' . voleibol_sets_para_ganar($torneo) . ' sets. No se guardó nada: usa "Guardar sets" para guardar cómo va.');
        }
        [$setsLocal, $setsVisita] = $revision['marcador'];

        foreach ($partidos as &$p) {
            if ((int) $p['id'] !== $partidoId) {
                continue;
            }
            $p['sets'] = voleibol_sets_a_texto($sets);
            $p['marcador_local'] = empty($sets) ? null : $setsLocal;
            $p['marcador_visitante'] = empty($sets) ? null : $setsVisita;
            // El "periodo" en voleibol es el set en juego: los puntos y tarjetas que se
            // carguen después quedan como "Set 3", igual que "2do Tiempo" en fútbol.
            $p['cronometro_periodo'] = $revision['terminado'] ? max(1, count($sets)) : min($maximos, count($sets) + 1);
            if ($finalizar) {
                $p['estado'] = 'jugado';
                $p['cronometro_estado'] = 'finalizado';
            }
        }
        unset($p);
        partidos_guardar_todos($partidos, $torneo['id']);

        $textoSets = voleibol_sets_a_texto($sets) ?: 'sin sets';
        bitacora_registrar($finalizar ? 'partido_jugado' : 'sets_guardados', "Encuentro #{$partidoId}: sets {$textoSets} ({$setsLocal}-{$setsVisita})" . ($finalizar ? ', finalizado' : ''), $torneo['id']);
        redirigir_con_mensaje($urlLista, 'success', $finalizar
            ? "Encuentro finalizado {$setsLocal}-{$setsVisita} en sets. El resultado ya cuenta en la tabla."
            : "Sets guardados: van {$setsLocal}-{$setsVisita}.");
    }

    // Cronómetro del partido: controla qué minuto se sugiere al cargar un evento (ver
    // partido_cronometro_minuto() en app/Support/liga.php y el autocompletado del campo "Min."
    // en assets/js/app.js). 'iniciar' solo aplica desde 'detenido' para no reiniciar por
    // accidente un cronómetro que ya venía corriendo.
    if ($accion === 'cronometro_iniciar') {
        foreach ($partidos as &$p) {
            if ((int) $p['id'] === $partidoId && ($p['cronometro_estado'] ?? 'detenido') === 'detenido') {
                $p['cronometro_estado'] = 'corriendo';
                $p['cronometro_inicio'] = date('c');
                $p['cronometro_segundos'] = 0;
                $p['cronometro_extra_min'] = 0;
            }
        }
        unset($p);
        partidos_guardar_todos($partidos, $torneo['id']);
        $duracion = torneo_duracion_periodo_min($torneo);
        redirigir_con_mensaje($urlLista, 'success', "Cronómetro iniciado: {$duracion} minutos para este " . ($basketball ? 'cuarto' : 'tiempo') . '.');
    }

    // Tiempo añadido / reposición del periodo EN CURSO: en vez de dejar el reloj en 00:00
    // esperando, el árbitro suma los minutos que se van a jugar de más y la cuenta
    // regresiva (aquí y en la transmisión en vivo) los refleja al instante.
    if ($accion === 'cronometro_agregar_extra') {
        $minutosExtraNuevos = max(1, min(30, (int) ($_POST['minutos'] ?? 1)));
        foreach ($partidos as &$p) {
            if ((int) $p['id'] === $partidoId) {
                $p['cronometro_extra_min'] = min(60, partido_minutos_extra($p) + $minutosExtraNuevos);
            }
        }
        unset($p);
        partidos_guardar_todos($partidos, $torneo['id']);
        bitacora_registrar('tiempo_extra', "Encuentro #{$partidoId}: +{$minutosExtraNuevos} min de tiempo extra", $torneo['id']);
        redirigir_con_mensaje($urlLista, 'success', "Se agregaron {$minutosExtraNuevos} minutos extra a este periodo.");
    }

    if ($accion === 'cronometro_quitar_extra') {
        foreach ($partidos as &$p) {
            if ((int) $p['id'] === $partidoId) {
                $p['cronometro_extra_min'] = 0;
            }
        }
        unset($p);
        partidos_guardar_todos($partidos, $torneo['id']);
        redirigir_con_mensaje($urlLista, 'success', 'Tiempo extra descartado.');
    }

    // Alineación del encuentro: quién arranca de titular, quién queda en banca y en qué
    // posición juega cada uno hoy. El tope de titulares por equipo lo marca la modalidad
    // de la copa (5, 7 u 11 jugadores en cancha).
    if ($accion === 'guardar_alineacion') {
        $titularesEnviados = array_map('intval', (array) ($_POST['titular'] ?? []));
        $posicionesEnviadas = (array) ($_POST['posicion'] ?? []);
        $catalogoPosiciones = posiciones_catalogo($deporte);

        $filas = [];
        $titularesPorEquipo = [];
        foreach ($equiposDelPartido as $equipoIdLado) {
            foreach ($jugadoresPorEquipo[$equipoIdLado] ?? [] as $j) {
                // Los dados de baja en la plantilla no entran a la convocatoria (el
                // formulario los muestra en gris, pero no se guardan ni en la banca).
                // Tampoco los que tienen la inscripción pendiente de aprobación.
                if (!jugador_habilitado_plantilla($j)) {
                    continue;
                }
                $jugadorId = (int) $j['id'];
                $esTitular = in_array($jugadorId, $titularesEnviados, true);
                $posicion = (string) ($posicionesEnviadas[$jugadorId] ?? '');
                if (!isset($catalogoPosiciones[$posicion])) {
                    $posicion = '';
                }
                if ($esTitular) {
                    $titularesPorEquipo[$equipoIdLado] = ($titularesPorEquipo[$equipoIdLado] ?? 0) + 1;
                }
                $filas[] = [
                    'jugador_id' => $jugadorId,
                    'equipo_id' => $equipoIdLado,
                    'titular' => $esTitular,
                    'posicion' => $posicion,
                ];
            }
        }

        foreach ($titularesPorEquipo as $equipoIdLado => $cuantos) {
            if ($cuantos > $jugadoresEnCancha) {
                $nombreEquipo = $equiposPorId[$equipoIdLado]['nombre'] ?? 'ese equipo';
                redirigir_con_mensaje($urlLista, 'error', "{$nombreEquipo} tiene {$cuantos} titulares marcados y esta modalidad juega con {$jugadoresEnCancha}. Pasa a la banca los que sobran.");
            }
        }

        // Los suspendidos por partidos no se pueden alinear de ninguna forma (no es algo
        // que se pague: hay que cumplir la sanción). Se valida también en el servidor.
        $suspendidosAlGuardar = disciplina_suspendidos_para_partido($torneo['id'], $partido, $torneo, $partidos, $jugadoresPorId);
        foreach ($filas as $fila) {
            $jid = (int) $fila['jugador_id'];
            if (isset($suspendidosAlGuardar[$jid])) {
                $nombreJugador = jugador_nombre($jugadoresPorId[$jid] ?? null);
                redirigir_con_mensaje($urlLista, 'error', "{$nombreJugador} está suspendido para este encuentro ({$suspendidosAlGuardar[$jid]['detalle']}) y no puede ser alineado.");
            }
        }

        // Segunda barrera del bloqueo por multa: la casilla ya viene deshabilitada en la
        // pantalla, pero un envío directo del formulario podría colar a un moroso.
        // Solo cuenta la deuda EXIGIBLE para este encuentro (multas de jornadas
        // anteriores): la tarjeta de hoy se paga antes de la próxima jornada, no bloquea ya.
        if (torneo_bloquea_morosos($torneo)) {
            $deudas = sanciones_deuda_vigente_para_jornada($torneo['id'], $partidos, (int) ($partido['jornada'] ?? 0));
            foreach ($filas as $fila) {
                $jid = (int) $fila['jugador_id'];
                if (isset($deudas[$jid])) {
                    $nombreJugador = jugador_nombre($jugadoresPorId[$jid] ?? null);
                    $montoDebe = sancion_monto_texto($torneo, $deudas[$jid]['total']);
                    redirigir_con_mensaje($urlLista, 'error', "{$nombreJugador} tiene {$montoDebe} en multas pendientes y no puede ser alineado. Regístrale el pago en Sanciones o quítalo de la lista.");
                }
            }
        }

        db_guardar_alineacion($torneo['id'], $partidoId, $filas);
        bitacora_registrar('alineacion_guardada', 'Encuentro #' . $partidoId . ': alineación actualizada', $torneo['id']);
        redirigir_con_mensaje($urlLista, 'success', 'Alineación guardada.');
    }

    if ($accion === 'cronometro_alternar_pausa') {
        foreach ($partidos as &$p) {
            if ((int) $p['id'] === $partidoId) {
                $estado = $p['cronometro_estado'] ?? 'detenido';
                if ($estado === 'corriendo') {
                    $p['cronometro_segundos'] = partido_cronometro_segundos($p);
                    $p['cronometro_estado'] = 'pausado';
                    $p['cronometro_inicio'] = null;
                } elseif ($estado === 'pausado') {
                    $p['cronometro_estado'] = 'corriendo';
                    $p['cronometro_inicio'] = date('c');
                }
            }
        }
        unset($p);
        partidos_guardar_todos($partidos, $torneo['id']);
        redirigir_con_mensaje($urlLista, 'success', 'Cronómetro actualizado.');
    }

    // Finalizar el encuentro. Antes esto solo paraba el reloj y había que acordarse de ir
    // a la lista a marcar el switch de "Jugado": el resultado quedaba sin cerrar y no
    // entraba en la tabla. Ahora finalizar el cronómetro cierra el partido, que es lo que
    // uno espera cuando aprieta el botón al pitido final.
    if ($accion === 'cronometro_finalizar') {
        $periodoActual = (int) ($partido['cronometro_periodo'] ?? 1);
        $periodoMaximo = partido_periodo_maximo($deporte);

        // El marcador sale de los goles registrados, igual que en el resto de la app.
        [$mLocal, $mVisit] = marcador_jugado_desde_eventos($torneo['id'], $partido, $deporte);

        // Dos casos en los que se para el reloj pero el encuentro NO se puede dar por
        // jugado, porque marcarlo sería registrar un resultado que no existe:
        //   - se finalizó antes del último periodo (partido suspendido, o un clic de más);
        //   - quedó empatado en una copa que no admite empates.
        $incompleto = $periodoActual < $periodoMaximo;
        $empateProhibido = $mLocal === $mVisit && empty($torneo['permite_empates']);
        $cerrar = !$incompleto && !$empateProhibido;

        foreach ($partidos as &$p) {
            if ((int) $p['id'] !== $partidoId) {
                continue;
            }
            if (in_array($p['cronometro_estado'] ?? 'detenido', ['corriendo', 'pausado'], true)) {
                $p['cronometro_segundos'] = partido_cronometro_segundos($p);
                $p['cronometro_estado'] = 'finalizado';
                $p['cronometro_inicio'] = null;
            }
            if ($cerrar) {
                $p['estado'] = 'jugado';
                $p['marcador_local'] = $mLocal;
                $p['marcador_visitante'] = $mVisit;
            }
        }
        unset($p);
        partidos_guardar_todos($partidos, $torneo['id']);

        if ($cerrar) {
            bitacora_registrar('partido_jugado', "Encuentro #{$partidoId} finalizado desde el cronómetro con marcador {$mLocal}-{$mVisit}", $torneo['id']);
            redirigir_con_mensaje($urlLista, 'success', "Encuentro finalizado y marcado como jugado ({$mLocal}-{$mVisit}). El resultado ya cuenta en la tabla.");
        }

        if ($empateProhibido) {
            redirigir_con_mensaje($urlLista, 'error', 'Cronómetro finalizado, pero el encuentro quedó ' . $mLocal . '-' . $mVisit . ' y esta copa no permite empates. Registra los ' . mb_strtolower(etiqueta_anotaciones($deporte)) . ' que definen al ganador y vuelve a finalizar.');
        }

        redirigir_con_mensaje($urlLista, 'error', 'Cronómetro finalizado, pero el encuentro va en ' . mb_strtolower(partido_periodo_etiqueta($deporte, $periodoActual)) . ' y no se dio por jugado. Pasa al último periodo y finaliza ahí, o márcalo a mano si se suspendió.');
    }

    // Avanza de periodo (1er a 2do tiempo en fútbol; cuarto a cuarto en basketball). Cada
    // periodo nuevo lleva su propio cronómetro desde 00:00 (no es continuo entre tiempos/
    // cuartos, a diferencia de una pausa dentro del mismo periodo).
    if ($accion === 'cronometro_siguiente_periodo') {
        $maximo = partido_periodo_maximo($deporte);
        foreach ($partidos as &$p) {
            if ((int) $p['id'] === $partidoId) {
                $periodoActual = (int) ($p['cronometro_periodo'] ?? 1);
                if ($periodoActual < $maximo) {
                    $p['cronometro_periodo'] = $periodoActual + 1;
                    $p['cronometro_segundos'] = 0;
                    // El tiempo extra pertenece al periodo que se cerró: el nuevo arranca
                    // otra vez con la duración limpia configurada en la copa.
                    $p['cronometro_extra_min'] = 0;
                    if (($p['cronometro_estado'] ?? 'detenido') === 'corriendo') {
                        $p['cronometro_inicio'] = date('c');
                    }
                }
            }
        }
        unset($p);
        partidos_guardar_todos($partidos, $torneo['id']);
        redirigir_con_mensaje($urlLista, 'success', 'Periodo actualizado y cronómetro reiniciado.');
    }

    if ($accion === 'eliminar_evento') {
        $id = (int) $_POST['id'];
        $eventos = db_leer_eventos_partido($torneo['id'], $partidoId);
        $eventoBorrado = db_buscar_por_id($eventos, $id);
        $eventos = array_values(array_filter($eventos, fn($ev) => (int) $ev['id'] !== $id));
        db_guardar_eventos_partido($torneo['id'], $partidoId, $eventos);
        // Si el evento borrado era un gol, el marcador cambia: se recalcula desde los
        // eventos restantes. Un evento que no era gol (tarjeta/cambio) no toca el marcador.
        if ($eventoBorrado !== null && ($eventoBorrado['tipo'] ?? '') === 'gol') {
            partido_recalcular_marcador($torneo['id'], $partidoId, $partidos, $deporte);
        }
        // La multa de una tarjeta se retira junto con ella, salvo que ya se haya cobrado:
        // una sanción pagada es dinero recibido y no debe borrarse por corregir la ficha.
        $avisoSancion = '';
        if ($eventoBorrado !== null && in_array($eventoBorrado['tipo'] ?? '', ['amarilla', 'roja'], true)) {
            if (!sancion_borrar_por_evento($id) && torneo_cobra_multas($torneo)) {
                $avisoSancion = ' La multa asociada ya estaba cobrada, así que se conservó en el registro de sanciones.';
            }
        }
        if ($eventoBorrado !== null) {
            bitacora_registrar('evento_eliminado', 'Encuentro #' . $partidoId . ': ' . evento_descripcion($eventoBorrado, $jugadoresPorId, $deporte), $torneo['id']);
        }
        redirigir_con_mensaje($urlLista, 'success', 'Evento eliminado.' . $avisoSancion);
    }

    // Planilla rápida (fútbol / futsal): en vez de cargar gol por gol, la mesa escribe
    // cuántos goles, amarillas y rojas lleva cada jugador y se guarda todo de una vez.
    // Por dentro sigue siendo un evento por gol o tarjeta (goleadores, marcador, multas y
    // suspensiones no cambian): se compara contra lo ya registrado y solo se agrega o
    // quita la diferencia. Los autogoles no entran aquí: se cargan con el formulario
    // detallado, igual que un gol al que se le quiera poner minuto o asistencia.
    if ($accion === 'guardar_planilla') {
        if ($basketball) {
            redirigir_con_mensaje($urlLista, 'error', 'La planilla rápida es para fútbol: en basketball cada anotación vale distinto y se carga con su tipo.');
        }
        $enviado = (array) ($_POST['planilla'] ?? []);
        $eventos = db_leer_eventos_partido($torneo['id'], $partidoId);
        $periodoActual = (int) ($partido['cronometro_periodo'] ?? 1);
        $motivoRoja = motivos_falta_grave_catalogo($deporte)[0];
        $siguienteId = evento_nuevo_id();
        $agregados = [];
        $quitados = [];
        // Primero los cargados "en bruto" (sin minuto ni asistencia) y los más recientes:
        // al bajar un número se conserva lo que se capturó con detalle.
        $prioridadQuitar = fn(array $ev): array => [
            ($ev['minuto'] ?? null) === null && empty($ev['asistencia_jugador_id']) ? 0 : 1,
            -(int) $ev['id'],
        ];

        foreach ($equiposDelPartido as $eqId) {
            $roster = array_map('intval', array_column($jugadoresPorEquipo[$eqId] ?? [], 'id'));
            foreach ((array) ($enviado[$eqId] ?? []) as $jidTexto => $valores) {
                $jid = (int) $jidTexto;
                // 0 = "sin identificar" (solo para goles). Cualquier otro id tiene que ser de
                // la plantilla de ESE equipo: un envío manipulado no puede tocar a otros.
                if (($jid !== 0 && !in_array($jid, $roster, true)) || !is_array($valores)) {
                    continue;
                }
                foreach (['gol', 'amarilla', 'roja'] as $tipo) {
                    if (!isset($valores[$tipo]) || ($jid === 0 && $tipo !== 'gol')) {
                        continue;
                    }
                    $deseado = max(0, min(99, (int) $valores[$tipo]));
                    $actuales = array_values(array_filter($eventos, fn($ev) => ($ev['tipo'] ?? '') === $tipo
                        && (int) ($ev['equipo_id'] ?? 0) === $eqId
                        && (int) ($ev['jugador_id'] ?? 0) === $jid
                        && ($tipo !== 'gol' || ($ev['tipo_gol'] ?? '') !== 'autogol')));
                    $diferencia = $deseado - count($actuales);

                    for ($i = 0; $i < $diferencia; $i++) {
                        $nuevo = [
                            'id' => $siguienteId++,
                            'tipo' => $tipo,
                            'equipo_id' => $eqId,
                            'jugador_id' => $jid === 0 ? null : $jid,
                            'jugador_entra_id' => null,
                            'minuto' => null,
                            'tipo_gol' => $tipo === 'gol' ? tipos_anotacion_catalogo($deporte)[0] : null,
                            'asistencia_jugador_id' => null,
                            'motivo' => $tipo === 'roja' ? $motivoRoja : null,
                            'periodo' => $periodoActual,
                        ];
                        $eventos[] = $nuevo;
                        $agregados[] = $nuevo;
                    }

                    if ($diferencia < 0) {
                        usort($actuales, fn($a, $b) => $prioridadQuitar($a) <=> $prioridadQuitar($b));
                        $sobran = array_slice($actuales, 0, -$diferencia);
                        $idsQuitar = array_map(fn($ev) => (int) $ev['id'], $sobran);
                        $eventos = array_values(array_filter($eventos, fn($ev) => !in_array((int) $ev['id'], $idsQuitar, true)));
                        $quitados = array_merge($quitados, $sobran);
                    }
                }
            }
        }

        if (empty($agregados) && empty($quitados)) {
            redirigir_con_mensaje($urlLista, 'success', 'La planilla no tenía cambios.');
        }

        db_guardar_eventos_partido($torneo['id'], $partidoId, $eventos);
        partido_recalcular_marcador($torneo['id'], $partidoId, $partidos, $deporte);

        // Multas: las tarjetas nuevas generan la suya y las quitadas la retiran (salvo que
        // ya esté cobrada, igual que al borrar una tarjeta suelta).
        $avisos = '';
        foreach ($quitados as $ev) {
            if (in_array($ev['tipo'], ['amarilla', 'roja'], true) && !sancion_borrar_por_evento((int) $ev['id']) && torneo_cobra_multas($torneo)) {
                $avisos = ' Alguna multa de una tarjeta quitada ya estaba cobrada y se conservó en Sanciones.';
            }
        }
        foreach ($agregados as $ev) {
            if (in_array($ev['tipo'], ['amarilla', 'roja'], true)) {
                $ev['partido_id'] = $partidoId;
                sancion_crear_por_evento($torneo['id'], $ev, $torneo);
                $avisos .= disciplina_aviso_por_tarjeta($torneo['id'], $torneo, $ev, $jugadoresPorId);
            }
        }

        $nombres = [
            'gol' => [mb_strtolower(etiqueta_anotacion($deporte)), mb_strtolower(etiqueta_anotaciones($deporte))],
            'amarilla' => es_voleibol($deporte) ? ['amonestación', 'amonestaciones'] : ['amarilla', 'amarillas'],
            'roja' => es_voleibol($deporte) ? ['sanción', 'sanciones'] : ['roja', 'rojas'],
        ];
        $resumen = [];
        foreach (['+' => $agregados, '-' => $quitados] as $signo => $lista) {
            foreach (array_count_values(array_column($lista, 'tipo')) as $tipo => $n) {
                $resumen[] = $signo . $n . ' ' . $nombres[$tipo][$n === 1 ? 0 : 1];
            }
        }
        $textoResumen = implode(', ', $resumen);
        bitacora_registrar('planilla_guardada', "Encuentro #{$partidoId}: planilla rápida ({$textoResumen})", $torneo['id']);
        redirigir_con_mensaje($urlLista, 'success', "Planilla guardada ({$textoResumen})." . $avisos);
    }

    if (in_array($accion, ['agregar_gol', 'agregar_tarjeta', 'agregar_cambio'], true)) {
        $equipoId = (int) ($_POST['equipo_id'] ?? 0);
        if (!in_array($equipoId, $equiposDelPartido, true)) {
            redirigir_con_mensaje($urlLista, 'error', 'Selecciona un equipo válido para este encuentro.');
        }
        $rosterEquipo = array_column($jugadoresPorEquipo[$equipoId] ?? [], 'id');
        $minuto = ($_POST['minuto'] ?? '') !== '' ? (int) $_POST['minuto'] : null;

        $evento = [
            'equipo_id' => $equipoId,
            'jugador_id' => null,
            'jugador_entra_id' => null,
            'minuto' => $minuto,
            'tipo_gol' => null,
            'asistencia_jugador_id' => null,
            'motivo' => null,
            // Periodo tomado del cronómetro en este momento (1er/2do tiempo o cuarto 1-4),
            // para poder mostrar "4' Cambio ... · 1er Tiempo" en la ficha y la transmisión.
            'periodo' => (int) ($partido['cronometro_periodo'] ?? 1),
        ];

        if ($accion === 'agregar_gol') {
            // jugador_id = 0 significa "sin identificar": el gol cuenta para el marcador
            // aunque el equipo todavía no tenga cargada su plantilla. Es lo que pasa en la
            // primera fecha, cuando hay promociones que aún no mandaron su nómina; obligar
            // a elegir un jugador empujaría a inventar uno solo para poder anotar el gol.
            $jugadorId = (int) ($_POST['jugador_id'] ?? 0);
            if ($jugadorId !== 0 && !in_array($jugadorId, $rosterEquipo, true)) {
                redirigir_con_mensaje($urlLista, 'error', forma_genero($torneo['genero'] ?? null, 'Selecciona un jugador válido de ese equipo.', 'Selecciona una jugadora válida de ese equipo.'));
            }
            if ($jugadorId === 0) {
                $jugadorId = null;
            }
            $catalogoTipoGol = tipos_anotacion_catalogo($deporte);
            $tipoGolDefault = $catalogoTipoGol[0];
            $tipoGol = (string) ($_POST['tipo_gol'] ?? $tipoGolDefault);
            $asistenciaId = (int) ($_POST['asistencia_jugador_id'] ?? 0);

            $evento['tipo'] = 'gol';
            $evento['jugador_id'] = $jugadorId;
            $evento['tipo_gol'] = in_array($tipoGol, $catalogoTipoGol, true) ? $tipoGol : $tipoGolDefault;
            $evento['asistencia_jugador_id'] = in_array($asistenciaId, $rosterEquipo, true) ? $asistenciaId : null;
        }

        if ($accion === 'agregar_tarjeta') {
            // Igual que el gol, se acepta sin identificar para que la ficha refleje lo que
            // pasó en la cancha. Ojo: una tarjeta sin jugador NO genera multa ni cuenta
            // para las suspensiones — no hay a quién cobrarle ni a quién suspender. La
            // pantalla lo avisa para que nadie crea que quedó registrada la sanción.
            $jugadorId = (int) ($_POST['jugador_id'] ?? 0);
            if ($jugadorId !== 0 && !in_array($jugadorId, $rosterEquipo, true)) {
                redirigir_con_mensaje($urlLista, 'error', forma_genero($torneo['genero'] ?? null, 'Selecciona un jugador válido de ese equipo.', 'Selecciona una jugadora válida de ese equipo.'));
            }
            if ($jugadorId === 0) {
                $jugadorId = null;
            }
            $color = (string) ($_POST['color'] ?? 'amarilla') === 'roja' ? 'roja' : 'amarilla';
            $catalogoMotivo = motivos_falta_grave_catalogo($deporte);
            $motivoDefault = $catalogoMotivo[0];
            $motivo = (string) ($_POST['motivo'] ?? $motivoDefault);

            $evento['tipo'] = $color;
            $evento['jugador_id'] = $jugadorId;
            $evento['motivo'] = $color === 'roja' ? (in_array($motivo, $catalogoMotivo, true) ? $motivo : $motivoDefault) : null;
        }

        if ($accion === 'agregar_cambio') {
            $jugadorSaleId = (int) ($_POST['jugador_id'] ?? 0);
            $jugadorEntraId = (int) ($_POST['jugador_entra_id'] ?? 0);
            if (!in_array($jugadorSaleId, $rosterEquipo, true) || !in_array($jugadorEntraId, $rosterEquipo, true)) {
                redirigir_con_mensaje($urlLista, 'error', forma_genero($torneo['genero'] ?? null, 'Selecciona jugadores válidos de ese equipo.', 'Selecciona jugadoras válidas de ese equipo.'));
            }
            if ($jugadorSaleId === $jugadorEntraId) {
                redirigir_con_mensaje($urlLista, 'error', forma_genero($torneo['genero'] ?? null, 'El jugador que entra y el que sale no pueden ser el mismo.', 'La jugadora que entra y la que sale no pueden ser la misma.'));
            }

            $evento['tipo'] = 'cambio';
            $evento['jugador_id'] = $jugadorSaleId;
            $evento['jugador_entra_id'] = $jugadorEntraId;
        }

        $eventos = db_leer_eventos_partido($torneo['id'], $partidoId);
        $evento['id'] = evento_nuevo_id();
        $eventos[] = $evento;
        db_guardar_eventos_partido($torneo['id'], $partidoId, $eventos);
        // Al registrar un gol, el marcador se actualiza automáticamente reflejando
        // todos los goles del partido (fútbol +1; basketball +1/2/3; autogol al rival).
        if ($accion === 'agregar_gol') {
            partido_recalcular_marcador($torneo['id'], $partidoId, $partidos, $deporte);
        }

        // Una tarjeta genera automáticamente su multa con la tarifa vigente de la copa;
        // el jugador queda en deuda (y por tanto no habilitado) hasta que la pague.
        $avisoMulta = '';
        if ($accion === 'agregar_tarjeta') {
            $evento['partido_id'] = $partidoId;
            sancion_crear_por_evento($torneo['id'], $evento, $torneo);
            $montoAplicado = torneo_multa($torneo, (string) $evento['tipo']);
            if ($montoAplicado > 0) {
                $avisoMulta = ' Se generó una multa de ' . sancion_monto_texto($torneo, $montoAplicado)
                    . (torneo_bloquea_morosos($torneo) ? ': el jugador no podrá alinearse hasta pagarla.' : '.');
            }
        }

        // Aviso inmediato si esta tarjeta detonó una suspensión: es el momento en que el
        // organizador puede avisarle al equipo que se queda sin ese jugador.
        $avisoSuspension = '';
        if ($accion === 'agregar_tarjeta') {
            $avisoSuspension = disciplina_aviso_por_tarjeta($torneo['id'], $torneo, $evento, $jugadoresPorId);
        }

        bitacora_registrar('evento_agregado', 'Encuentro #' . $partidoId . ': ' . evento_descripcion($evento, $jugadoresPorId, $deporte), $torneo['id']);
        redirigir_con_mensaje($urlLista, 'success', 'Evento agregado.' . $avisoMulta . $avisoSuspension);
    }
}

$eventos = db_leer_eventos_partido($torneo['id'], $partidoId);
usort($eventos, fn($a, $b) => ($a['minuto'] ?? 999) <=> ($b['minuto'] ?? 999));

// Conteo para la planilla rápida: cuántos goles (sin autogoles), amarillas y rojas lleva
// cada jugador en este encuentro. Clave 0 = goles "sin identificar".
$conteoPlanilla = [];
foreach ($eventos as $ev) {
    $tipoEv = (string) ($ev['tipo'] ?? '');
    if (!in_array($tipoEv, ['gol', 'amarilla', 'roja'], true) || ($tipoEv === 'gol' && ($ev['tipo_gol'] ?? '') === 'autogol')) {
        continue;
    }
    $eqEv = (int) ($ev['equipo_id'] ?? 0);
    $jEv = (int) ($ev['jugador_id'] ?? 0);
    $conteoPlanilla[$eqEv][$jEv][$tipoEv] = ($conteoPlanilla[$eqEv][$jEv][$tipoEv] ?? 0) + 1;
}

// Alineación ya guardada de este encuentro. Si todavía no hay ninguna, el formulario se
// pinta con la posición habitual de cada jugador (jugadores.posicion) como sugerencia y
// nadie marcado de titular: el organizador arma la alineación del día en un par de clics.
$alineacion = db_leer_alineacion($torneo['id'], $partidoId);
$alineacionPorJugador = alineacion_por_jugador($alineacion);
$hayAlineacion = !empty($alineacion);
$titularesPorEquipoActual = [];
foreach ($alineacion as $filaAlineacion) {
    if (!empty($filaAlineacion['titular'])) {
        $eqId = (int) $filaAlineacion['equipo_id'];
        $titularesPorEquipoActual[$eqId] = ($titularesPorEquipoActual[$eqId] ?? 0) + 1;
    }
}

// Marcador en vivo calculado directamente desde los goles registrados. Es la fuente de
// verdad del resultado: cada gol que se agrega abajo se refleja aquí y en la tabla.
// En voleibol el marcador son los sets ganados (y un W.O. conserva el suyo).
$setsPartido = es_voleibol($deporte) ? voleibol_sets_de_partido($partido) : [];
if (es_voleibol($deporte)) {
    [$marcadorLocalVivo, $marcadorVisitanteVivo] = !empty($partido['por_default'])
        ? [(int) $partido['marcador_local'], (int) $partido['marcador_visitante']]
        : voleibol_sets_ganados($setsPartido);
} else {
    [$marcadorLocalVivo, $marcadorVisitanteVivo] = marcador_desde_eventos(
        $eventos,
        (int) $partido['equipo_local'],
        (int) $partido['equipo_visitante'],
        $deporte
    );
}

// El encuentro se está registrando antes de su fecha programada: se ofrece (vía modal)
// adelantar la fecha a hoy o seguir registrando eventos sin tocar la fecha.
$hoy = date('Y-m-d');
$fechaEsFutura = ($partido['fecha'] ?? '') > $hoy && ($partido['estado'] ?? '') !== 'jugado';

// Multas EXIGIBLES para este encuentro (nacidas en jornadas anteriores): la alineación
// marca (y según la copa, bloquea) a los que deben. La tarjeta de esta misma jornada se
// paga antes de la próxima — no marca moroso ya. Se consulta una vez para toda la pantalla.
$deudaPorJugador = torneo_cobra_multas($torneo)
    ? sanciones_deuda_vigente_para_jornada($torneo['id'], $partidos, (int) ($partido['jornada'] ?? 0))
    : [];

// Suspendidos para ESTE partido por roja o por acumulación de amarillas. A diferencia de
// la multa, una suspensión no se puede "pagar": el jugador simplemente no puede alinearse.
$suspendidosPartido = disciplina_suspendidos_para_partido($torneo['id'], $partido, $torneo, $partidos, $jugadoresPorId);

$seccion_activa = 'partidos';
$titulo_pagina = 'Ficha del partido';

vista_admin('admin/partido_eventos', compact(
    'alineacionPorJugador',
    'basketball',
    'conteoPlanilla',
    'deudaPorJugador',
    'suspendidosPartido',
    'deporte',
    'equipoLocal',
    'equipoVisitante',
    'equiposDelPartido',
    'equiposPorId',
    'etJugador',
    'eventos',
    'fechaEsFutura',
    'hayAlineacion',
    'jugadoresEnCancha',
    'jugadoresPorEquipo',
    'jugadoresPorId',
    'marcadorLocalVivo',
    'marcadorVisitanteVivo',
    'partido',
    'partidoId',
    'resultadoBloqueado',
    'seccion_activa',
    'setsPartido',
    'titularesPorEquipoActual',
    'titulo_pagina',
    'torneo'
));
