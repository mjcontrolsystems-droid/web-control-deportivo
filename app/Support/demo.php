<?php
declare(strict_types=1);

/**
 * Copa de demostración de voleibol, con datos inventados.
 *
 * Sirve para enseñar la plataforma (igual que la Copa Estrellas en basketball): ocho
 * equipos con plantilla completa, la temporada regular con casi todas las jornadas ya
 * jugadas (sets válidos según la FIVB, puntos por jugador, alguna tarjeta) y la última
 * jornada por jugar, para que se vean la tabla, el calendario, los anotadores y la ficha.
 *
 * Los resultados salen de un generador con semilla fija: la demo sale igual cada vez.
 */

const DEMO_VOLEY_EQUIPOS = [
    ['Halcones de Antigua', 'Antigua Guatemala', 'HAL', '#1848aa', '#fac81e', 9],
    ['Tiburones del Puerto', 'Puerto San José', 'TIB', '#0e7c86', '#ffffff', 8],
    ['Panteras de Xela', 'Quetzaltenango', 'PAN', '#2b2d42', '#ef233c', 7],
    ['Cóndores de Cobán', 'Cobán', 'CON', '#6a4c93', '#f7b801', 6],
    ['Jaguares del Petén', 'Flores', 'JAG', '#e76f51', '#264653', 5],
    ['Volcanes de Escuintla', 'Escuintla', 'VOL', '#c1121f', '#fdf0d5', 5],
    ['Delfines de Izabal', 'Puerto Barrios', 'DEL', '#219ebc', '#023047', 4],
    ['Quetzales de Zacapa', 'Zacapa', 'QUE', '#2a9d8f', '#e9c46a', 3],
];

const DEMO_VOLEY_NOMBRES = ['Andrea', 'Sofía', 'Valeria', 'Camila', 'Daniela', 'Gabriela', 'Lucía', 'Fernanda', 'Mariana', 'Paola',
    'Diego', 'José', 'Luis', 'Carlos', 'Javier', 'Pablo', 'Andrés', 'Mateo', 'Kevin', 'Esteban'];
const DEMO_VOLEY_APELLIDOS = ['López', 'García', 'Pérez', 'Morales', 'Hernández', 'Castillo', 'Ramírez', 'Mejía', 'Orellana', 'Barrios',
    'Cifuentes', 'Ajú', 'Xicará', 'Monterroso', 'Arévalo', 'Toledo', 'Samayoa', 'Godoy', 'Recinos', 'Quiñónez'];

// Plantilla tipo de 10: dos armadores, dos opuestos, tres centrales, dos puntas y el líbero.
const DEMO_VOLEY_POSICIONES = ['armador', 'opuesto', 'central', 'central', 'punta', 'punta', 'libero', 'armador', 'punta', 'central'];

/**
 * Crea la copa demo y devuelve su id. Queda a nombre de $usuarioId.
 */
function demo_crear_copa_voleibol(int $usuarioId): int
{
    mt_srand(2026);

    $slug = 'copa-voley-demo';
    for ($n = 2; torneos_obtener_por_slug($slug) !== null; $n++) {
        $slug = 'copa-voley-demo-' . $n;
    }

    // Seis sábados atrás: seis jornadas ya jugadas y la séptima este fin de semana.
    $primerSabado = strtotime('saturday this week') - 6 * 7 * 86400;
    $hoy = date('Y-m-d');

    $torneoId = torneos_guardar([
        'slug' => $slug,
        'nombre' => 'Copa Voleibol Demo',
        'subtitulo' => 'Liga Nacional Mixta ' . date('Y'),
        'temporada' => date('Y'),
        'descripcion' => 'Ocho clubes, todos contra todos a cinco sets y fase final con semifinales, tercer lugar y final. Datos de demostración.',
        'sede_principal' => 'Coliseo Municipal',
        'logo' => '',
        'reglamento' => '',
        'reglamento_nombre' => '',
        'color_primario' => '#1848aa',
        'color_secundario' => '#0e7c86',
        'color_acento' => '#fac81e',
        'fecha_inicio' => date('Y-m-d', $primerSabado),
        'fecha_fin' => date('Y-m-d', $primerSabado + 10 * 7 * 86400),
        'formato' => 'Liga a una vuelta + fase final',
        'instagram' => '',
        'facebook' => '',
        'tiktok' => '',
        'whatsapp' => '',
        'hero_frase' => 'Cada punto cuenta, cada set se pelea',
        'deporte' => 'voleibol',
        'genero' => 'mixto',
        'modo' => FORMATO_CAMPEONATO,
        'vueltas' => 1,
        'modalidad' => 'voley6_5',
        'duracion_periodo_min' => null,
        'num_equipos' => count(DEMO_VOLEY_EQUIPOS),
        'fases_playoff' => ['semifinal', 'tercer_lugar', 'final'],
        'num_grupos' => 0,
        'clasifican_por_grupo' => 2,
        'podio_publicado' => false,
        'permite_empates' => false,
        'puntos_victoria' => 3,
        'puntos_empate' => 0,
        'puntos_derrota' => 0,
        'multa_amarilla' => 0,
        'multa_roja' => 25,
        'sancion_bloquea' => false,
        'partidos_suspension_roja' => 1,
        'amarillas_para_suspension' => 0,
        'partidos_suspension_amarillas' => 1,
        'moneda' => 'Q',
        'inscripciones_abiertas' => true,
        'cuota_inscripcion' => 0,
        'cuota_arbitraje' => 0,
        'multas_al_equipo' => true,
        'es_predeterminado' => false,
        'activo' => true,
    ], $usuarioId);

    // --- Equipos y plantillas -------------------------------------------------------
    $equipos = [];
    $jugadores = [];
    $fuerza = [];
    $plantilla = [];
    $siguienteEquipo = equipo_nuevo_id();
    $siguienteJugador = jugador_nuevo_id();
    foreach (DEMO_VOLEY_EQUIPOS as $i => [$nombre, $ciudad, $siglas, $c1, $c2, $nivel]) {
        $equipoId = $siguienteEquipo++;
        $equipos[] = [
            'id' => $equipoId,
            'nombre' => $nombre,
            'ciudad' => $ciudad,
            'sede' => 'Gimnasio de ' . $ciudad,
            'entrenador' => DEMO_VOLEY_NOMBRES[($i * 3 + 10) % 20] . ' ' . DEMO_VOLEY_APELLIDOS[($i * 7) % 20],
            'fundacion' => (string) (1985 + $i * 4),
            'color_primario' => $c1,
            'color_secundario' => $c2,
            'logo' => '',
            'descripcion' => 'Club de demostración de ' . $ciudad . '.',
            'grupo' => '',
            'cabeza_serie' => false,
            'siglas' => $siglas,
        ];
        $fuerza[$equipoId] = $nivel;
        foreach (DEMO_VOLEY_POSICIONES as $k => $posicion) {
            $jugadorId = $siguienteJugador++;
            $jugadores[] = [
                'id' => $jugadorId,
                'equipo_id' => $equipoId,
                'dorsal' => (string) ($posicion === 'libero' ? 7 : $k + 1 + ($k >= 6 ? 1 : 0)),
                'nombre' => DEMO_VOLEY_NOMBRES[mt_rand(0, 19)] . ' ' . DEMO_VOLEY_APELLIDOS[mt_rand(0, 19)],
                'activo' => true,
                'posicion' => $posicion,
                'foto' => '',
            ];
            $plantilla[$equipoId][] = ['id' => $jugadorId, 'posicion' => $posicion];
        }
    }
    equipos_guardar_todos($equipos, $torneoId);
    jugadores_guardar_todos($jugadores, $torneoId);

    // --- Calendario y resultados ----------------------------------------------------
    $horas = ['09:00', '10:30', '12:00', '13:30'];
    $jornadas = generar_fixture_round_robin(array_column($equipos, 'id'), 1);
    $partidos = [];
    $eventosPorPartido = [];
    $siguientePartido = partido_nuevo_id();
    foreach ($jornadas as $j => $cruces) {
        $fecha = date('Y-m-d', $primerSabado + $j * 7 * 86400);
        foreach ($cruces as $k => [$local, $visitante]) {
            $partidoId = $siguientePartido++;
            $jugado = $fecha < $hoy;
            $sets = $jugado ? demo_voley_sets($fuerza[$local], $fuerza[$visitante]) : [];
            [$sl, $sv] = voleibol_sets_ganados($sets);
            $partidos[] = [
                'id' => $partidoId,
                'jornada' => $j + 1,
                'equipo_local' => $local,
                'equipo_visitante' => $visitante,
                'fecha' => $fecha,
                'hora' => $horas[$k % count($horas)],
                'cancha' => 'Coliseo Municipal',
                'estado' => $jugado ? 'jugado' : 'programado',
                'marcador_local' => $jugado ? $sl : null,
                'marcador_visitante' => $jugado ? $sv : null,
                'fase' => 'grupos',
                'arbitro' => 'Árbitro ' . DEMO_VOLEY_APELLIDOS[mt_rand(0, 19)],
                'observaciones' => '',
                'por_default' => false,
                'cronometro_estado' => $jugado ? 'finalizado' : 'detenido',
                'cronometro_inicio' => null,
                'cronometro_segundos' => 0,
                'cronometro_periodo' => $jugado ? count($sets) : 1,
                'cronometro_extra_min' => 0,
                'sets' => voleibol_sets_a_texto($sets),
            ];
            if ($jugado) {
                $eventosPorPartido[$partidoId] = demo_voley_eventos($sets, $local, $visitante, $plantilla);
            }
        }
    }
    partidos_guardar_todos($partidos, $torneoId);

    $siguienteEvento = evento_nuevo_id();
    foreach ($eventosPorPartido as $partidoId => $eventos) {
        foreach ($eventos as &$ev) {
            $ev['id'] = $siguienteEvento++;
        }
        unset($ev);
        db_guardar_eventos_partido($torneoId, $partidoId, $eventos);
    }

    // --- Patrocinadores -------------------------------------------------------------
    $siguientePatro = patrocinador_nuevo_id();
    patrocinadores_guardar_todos([
        ['id' => $siguientePatro, 'nombre' => 'Bebidas Volcán', 'nivel' => 'oficial', 'url' => '', 'logo' => '', 'orden' => 0],
        ['id' => $siguientePatro + 1, 'nombre' => 'Deportes La Red', 'nivel' => 'oro', 'url' => '', 'logo' => '', 'orden' => 1],
        ['id' => $siguientePatro + 2, 'nombre' => 'Farmacia El Remate', 'nivel' => 'plata', 'url' => '', 'logo' => '', 'orden' => 2],
    ], $torneoId);

    return $torneoId;
}

/**
 * Resultado de un partido a cinco sets: gana más seguido el de más nivel, pero hay
 * sorpresas y partidos a cinco. Cada set respeta las reglas FIVB (a 25 o 15, por 2).
 *
 * @return array<int, array{0: int, 1: int}>
 */
function demo_voley_sets(int $nivelLocal, int $nivelVisita): array
{
    $probLocal = 0.5 + ($nivelLocal - $nivelVisita) * 0.07 + 0.04; // un poco de ventaja de local
    $probLocal = max(0.15, min(0.85, $probLocal));
    $sets = [];
    $local = 0;
    $visita = 0;
    while ($local < 3 && $visita < 3) {
        $decisivo = $local === 2 && $visita === 2;
        $objetivo = $decisivo ? 15 : 25;
        $ganaLocal = mt_rand() / mt_getrandmax() < $probLocal;
        if (mt_rand(1, 6) === 1) {
            // Set peleado: "deuce" que termina por dos.
            $perdedor = $objetivo - 1 + mt_rand(0, 3);
            $ganador = $perdedor + 2;
        } else {
            $ganador = $objetivo;
            $perdedor = $decisivo ? mt_rand(6, 13) : mt_rand(13, 23);
        }
        $sets[] = $ganaLocal ? [$ganador, $perdedor] : [$perdedor, $ganador];
        $ganaLocal ? $local++ : $visita++;
    }

    return $sets;
}

/**
 * Puntos de los jugadores (ataque, bloqueo y saque) y alguna tarjeta, repartidos por set.
 * Solo una parte de los puntos del set va a un jugador: el resto son errores del rival,
 * igual que en una planilla real.
 *
 * @return array<int, array>
 */
function demo_voley_eventos(array $sets, int $local, int $visitante, array $plantilla): array
{
    // Quién anota más según su posición (el líbero no ataca ni saca).
    $peso = ['opuesto' => 6, 'punta' => 5, 'central' => 4, 'armador' => 2, 'libero' => 0];
    $eventos = [];
    foreach ($sets as $i => $puntosSet) {
        foreach ([$local, $visitante] as $lado => $equipoId) {
            $candidatos = [];
            foreach ($plantilla[$equipoId] as $j) {
                for ($p = 0; $p < $peso[$j['posicion']]; $p++) {
                    $candidatos[] = $j;
                }
            }
            $anotados = (int) round($puntosSet[$lado] * (mt_rand(55, 70) / 100));
            for ($n = 0; $n < $anotados; $n++) {
                $j = $candidatos[mt_rand(0, count($candidatos) - 1)];
                $tirada = mt_rand(1, 100);
                $tipo = $j['posicion'] === 'central' && $tirada <= 40 ? 'bloqueo' : ($tirada <= 12 ? 'saque' : ($tirada <= 22 ? 'bloqueo' : 'ataque'));
                $eventos[] = demo_voley_evento('gol', $equipoId, $j['id'], $i + 1, $tipo, null);
            }
            if (mt_rand(1, 12) === 1) {
                $j = $plantilla[$equipoId][mt_rand(0, 9)];
                $eventos[] = demo_voley_evento('amarilla', $equipoId, $j['id'], $i + 1, null, null);
            }
        }
    }
    if (mt_rand(1, 10) === 1) {
        $j = $plantilla[$visitante][mt_rand(0, 9)];
        $eventos[] = demo_voley_evento('roja', $visitante, $j['id'], count($sets), null, 'penalizacion');
    }

    return $eventos;
}

function demo_voley_evento(string $tipo, int $equipoId, int $jugadorId, int $set, ?string $tipoPunto, ?string $motivo): array
{
    return [
        'id' => 0,
        'tipo' => $tipo,
        'equipo_id' => $equipoId,
        'jugador_id' => $jugadorId,
        'jugador_entra_id' => null,
        'minuto' => null,
        'tipo_gol' => $tipoPunto,
        'asistencia_jugador_id' => null,
        'motivo' => $motivo,
        'periodo' => $set,
    ];
}
