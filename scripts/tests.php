<?php
declare(strict_types=1);

/**
 * Pruebas automatizadas de la lógica crítica de la plataforma: el cálculo de la tabla
 * de posiciones y el marcador derivado de eventos. Son las dos piezas donde un error
 * pasa desapercibido más tiempo y hace más daño (clasificaciones incorrectas).
 *
 * No necesitan base de datos ni servidor: solo PHP.
 *
 * Uso:  php scripts/tests.php
 * Sale con código 0 si todo pasa, 1 si algo falla (útil para CI).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo puede ejecutarse desde la línea de comandos (CLI).');
}

// Solo las reglas de dominio: estas pruebas no tocan la base de datos a propósito, así
// que no cargan el bootstrap completo (ver config/bootstrap.php).
require_once __DIR__ . '/../app/Support/tabla.php';
require_once __DIR__ . '/../app/Support/liga.php';
require_once __DIR__ . '/../app/Support/voleibol.php';
require_once __DIR__ . '/../app/Models/Jugador.php';
require_once __DIR__ . '/../app/Support/fixture.php';

$fallos = 0;
$pruebas = 0;

function check(string $desc, mixed $obtenido, mixed $esperado): void
{
    global $fallos, $pruebas;
    $pruebas++;
    $ok = $obtenido === $esperado;
    if (!$ok) {
        $fallos++;
    }
    printf("%s | %s\n", $ok ? '  OK  ' : ' FALLA', $desc);
    if (!$ok) {
        printf("        obtenido: %s\n        esperado: %s\n", var_export($obtenido, true), var_export($esperado, true));
    }
}

// ---------------------------------------------------------------------------
echo "== calcular_tabla: fútbol (3-1-0, con empates) ==\n";

$equipos = [
    ['id' => 1, 'nombre' => 'Leones', 'ciudad' => ''],
    ['id' => 2, 'nombre' => 'Tigres', 'ciudad' => ''],
    ['id' => 3, 'nombre' => 'Pumas', 'ciudad' => ''],
];
$reglasFutbol = ['permite_empates' => true, 'puntos_victoria' => 3, 'puntos_empate' => 1, 'puntos_derrota' => 0];
$partidos = [
    // Leones 2-0 Tigres | Tigres 1-1 Pumas | Pumas 0-1 Leones
    ['id' => 10, 'fase' => 'grupos', 'estado' => 'jugado', 'fecha' => '2026-07-01', 'hora' => '10:00', 'equipo_local' => 1, 'equipo_visitante' => 2, 'marcador_local' => 2, 'marcador_visitante' => 0],
    ['id' => 11, 'fase' => 'grupos', 'estado' => 'jugado', 'fecha' => '2026-07-08', 'hora' => '10:00', 'equipo_local' => 2, 'equipo_visitante' => 3, 'marcador_local' => 1, 'marcador_visitante' => 1],
    ['id' => 12, 'fase' => 'grupos', 'estado' => 'jugado', 'fecha' => '2026-07-15', 'hora' => '10:00', 'equipo_local' => 3, 'equipo_visitante' => 1, 'marcador_local' => 0, 'marcador_visitante' => 1],
    // Programado: no debe contar
    ['id' => 13, 'fase' => 'grupos', 'estado' => 'programado', 'fecha' => '2026-07-22', 'hora' => '10:00', 'equipo_local' => 1, 'equipo_visitante' => 3, 'marcador_local' => null, 'marcador_visitante' => null],
    // Playoff: no debe contar para la tabla
    ['id' => 14, 'fase' => 'final', 'estado' => 'jugado', 'fecha' => '2026-08-01', 'hora' => '10:00', 'equipo_local' => 1, 'equipo_visitante' => 2, 'marcador_local' => 0, 'marcador_visitante' => 5],
];

$tabla = calcular_tabla($equipos, $partidos, $reglasFutbol);
$porNombre = [];
foreach ($tabla as $fila) {
    $porNombre[$fila['equipo']['nombre']] = $fila;
}

check('Leones lidera con 6 pts (2 victorias)', $porNombre['Leones']['pts'], 6);
check('Leones posición 1', $porNombre['Leones']['posicion'], 1);
check('Tigres y Pumas con 1 pt (empate entre sí)', [$porNombre['Tigres']['pts'], $porNombre['Pumas']['pts']], [1, 1]);
check('Leones PJ=2 (el programado y la final no cuentan)', $porNombre['Leones']['pj'], 2);
check('Leones DIF=+3 (3 a favor, 0 en contra)', $porNombre['Leones']['dif'], 3);
check('Tigres registra el empate (PE=1)', $porNombre['Tigres']['pe'], 1);
check('Desempate por DIF: Pumas (-1) arriba de Tigres (-2)', [$porNombre['Pumas']['posicion'], $porNombre['Tigres']['posicion']], [2, 3]);

// ---------------------------------------------------------------------------
echo "\n== calcular_tabla: basketball (2-0-1, sin empates) ==\n";

$reglasBasket = ['permite_empates' => false, 'puntos_victoria' => 2, 'puntos_empate' => 0, 'puntos_derrota' => 1];
$partidosBasket = [
    ['id' => 20, 'fase' => 'grupos', 'estado' => 'jugado', 'fecha' => '2026-07-01', 'hora' => '10:00', 'equipo_local' => 1, 'equipo_visitante' => 2, 'marcador_local' => 60, 'marcador_visitante' => 55],
    ['id' => 21, 'fase' => 'grupos', 'estado' => 'jugado', 'fecha' => '2026-07-08', 'hora' => '10:00', 'equipo_local' => 2, 'equipo_visitante' => 1, 'marcador_local' => 70, 'marcador_visitante' => 40],
];
$tablaB = calcular_tabla($equipos, $partidosBasket, $reglasBasket);
$porNombreB = [];
foreach ($tablaB as $fila) {
    $porNombreB[$fila['equipo']['nombre']] = $fila;
}
check('Basketball: 1 victoria + 1 derrota = 3 pts (2+1)', $porNombreB['Leones']['pts'], 3);
check('Basketball: Tigres también 3 pts, desempata DIF', $porNombreB['Tigres']['posicion'], 1);
check('Pumas sin jugar: 0 pts y última posición', [$porNombreB['Pumas']['pts'], $porNombreB['Pumas']['posicion']], [0, 3]);

// ---------------------------------------------------------------------------
echo "\n== marcador_desde_eventos: fútbol ==\n";

$evGol = fn(int $equipo, string $tipo = 'jugada') => ['tipo' => 'gol', 'equipo_id' => $equipo, 'tipo_gol' => $tipo];

check('2 goles local, 1 visitante', marcador_desde_eventos([$evGol(1), $evGol(1), $evGol(2)], 1, 2, 'futbol'), [2, 1]);
check('autogol del local suma al visitante', marcador_desde_eventos([$evGol(1, 'autogol')], 1, 2, 'futbol'), [0, 1]);
check('autogol del visitante suma al local', marcador_desde_eventos([$evGol(2, 'autogol')], 1, 2, 'futbol'), [1, 0]);
check('tarjetas y cambios no suman', marcador_desde_eventos([
    ['tipo' => 'amarilla', 'equipo_id' => 1],
    ['tipo' => 'cambio', 'equipo_id' => 2],
], 1, 2, 'futbol'), [0, 0]);
check('sin eventos: 0-0', marcador_desde_eventos([], 1, 2, 'futbol'), [0, 0]);

// ---------------------------------------------------------------------------
echo "\n== marcador_desde_eventos: basketball (1/2/3 puntos) ==\n";

check('libre+doble+triple local = 6', marcador_desde_eventos([
    $evGol(1, 'libre'), $evGol(1, 'doble'), $evGol(1, 'triple'),
], 1, 2, 'basketball'), [6, 0]);
check('triple visitante = 0-3', marcador_desde_eventos([$evGol(2, 'triple')], 1, 2, 'basketball'), [0, 3]);
check('tipo desconocido vale 1 (no revienta)', marcador_desde_eventos([$evGol(1, 'rarisimo')], 1, 2, 'basketball'), [1, 0]);

// ---------------------------------------------------------------------------
echo "\n== faltas_por_jugador: expulsión por acumulación ==\n";

$faltas = [];
for ($i = 0; $i < 5; $i++) {
    $faltas[] = ['tipo' => 'amarilla', 'jugador_id' => 7, 'equipo_id' => 1];
}
$faltas[] = ['tipo' => 'amarilla', 'jugador_id' => 8, 'equipo_id' => 1];
$conteo = faltas_por_jugador($faltas);
check('jugador 7 acumula 5 faltas (límite FIBA)', $conteo[7], LIMITE_FALTAS_EXPULSION);
check('jugador 8 solo 1', $conteo[8], 1);

// ---------------------------------------------------------------------------
echo "\n== Reglas por deporte: expulsión y duración de periodos ==\n";

check('fútbol: expulsión a las 2 amarillas (IFAB)', limite_faltas_expulsion('futbol'), 2);
check('basketball: expulsión a las 5 faltas (FIBA)', limite_faltas_expulsion('basketball'), 5);

check('fútbol 11 reglamentario: 45 min', torneo_duracion_periodo_min(['deporte' => 'futbol', 'modalidad' => 'futbol11']), 45);
check('fútbol 7 reglamentario: 25 min', torneo_duracion_periodo_min(['deporte' => 'futbol', 'modalidad' => 'futbol7']), 25);
check('fútbol sala reglamentario: 20 min', torneo_duracion_periodo_min(['deporte' => 'futbol', 'modalidad' => 'futbol5']), 20);
check('basketball FIBA reglamentario: 10 min', torneo_duracion_periodo_min(['deporte' => 'basketball', 'modalidad' => 'fiba']), 10);
check('basketball NBA: 12 min', torneo_duracion_periodo_min(['deporte' => 'basketball', 'modalidad' => 'nba']), 12);
check('duración personalizada gana a la reglamentaria', torneo_duracion_periodo_min(['deporte' => 'basketball', 'modalidad' => 'fiba', 'duracion_periodo_min' => 15]), 15);
check('copa vieja sin modalidad: default del deporte', torneo_duracion_periodo_min(['deporte' => 'futbol']), 45);
check('modalidad inválida cae al default', torneo_duracion_periodo_min(['deporte' => 'basketball', 'modalidad' => 'rarisima']), 10);

check('periodos fútbol: 2 tiempos', partido_periodo_maximo('futbol'), 2);
check('periodos basketball: 4 cuartos', partido_periodo_maximo('basketball'), 4);

// ---------------------------------------------------------------------------
echo "\n== Cronómetro: cuenta regresiva desde lo configurado + tiempo extra ==\n";

// Copa configurada a 15 min por tiempo (caso típico de liga amateur).
$copa15 = ['deporte' => 'futbol', 'modalidad' => 'futbol11', 'duracion_periodo_min' => 15];
$reloj0 = ['cronometro_estado' => 'pausado', 'cronometro_segundos' => 0, 'cronometro_periodo' => 1];

check('el periodo dura lo configurado (15 min = 900 s)', partido_duracion_periodo_segundos($reloj0, $copa15), 900);
check('recién iniciado quedan los 15 min completos', partido_cronometro_restante_segundos($reloj0, $copa15), 900);

$reloj10 = ['cronometro_estado' => 'pausado', 'cronometro_segundos' => 600, 'cronometro_periodo' => 1];
check('a los 10 min corridos quedan 5', partido_cronometro_restante_segundos($reloj10, $copa15), 300);

$relojAgotado = ['cronometro_estado' => 'pausado', 'cronometro_segundos' => 1200, 'cronometro_periodo' => 1];
check('pasado el tiempo el restante no baja de 0', partido_cronometro_restante_segundos($relojAgotado, $copa15), 0);

$relojExtra = ['cronometro_estado' => 'pausado', 'cronometro_segundos' => 900, 'cronometro_periodo' => 1, 'cronometro_extra_min' => 3];
check('el tiempo extra alarga el periodo (15+3 min)', partido_duracion_periodo_segundos($relojExtra, $copa15), 1080);
check('con 3 min extra quedan 180 s al cumplirse los 15', partido_cronometro_restante_segundos($relojExtra, $copa15), 180);
check('partido sin la columna de extras: 0 minutos', partido_minutos_extra($reloj0), 0);
check('minutos extra negativos se ignoran', partido_minutos_extra(['cronometro_extra_min' => -5]), 0);

// El minuto sugerido a los eventos no reinicia en cada tiempo/cuarto.
check('1er tiempo arranca en el minuto 0', partido_minuto_base(['cronometro_periodo' => 1], $copa15), 0);
check('2do tiempo de 15 min arranca en el 15', partido_minuto_base(['cronometro_periodo' => 2], $copa15), 15);
check('3er cuarto FIBA arranca en el 20', partido_minuto_base(['cronometro_periodo' => 3], ['deporte' => 'basketball', 'modalidad' => 'fiba']), 20);

check('partido jugado cuenta como finalizado', partido_finalizado(['estado' => 'jugado', 'cronometro_estado' => 'corriendo']), true);
check('cronómetro finalizado cuenta como finalizado', partido_finalizado(['estado' => 'programado', 'cronometro_estado' => 'finalizado']), true);
check('partido en curso no está finalizado', partido_finalizado(['estado' => 'programado', 'cronometro_estado' => 'corriendo']), false);

// ---------------------------------------------------------------------------
echo "\n== Formato: liga (solo puntos) vs campeonato (eliminación directa) ==\n";

$ligaConFases = ['modo' => 'liga', 'fases_playoff' => ['cuartos', 'final']];
$campeonato = ['modo' => 'copa', 'fases_playoff' => ['cuartos', 'final']];
$copaVieja = ['fases_playoff' => ['semifinal', 'final']];

check('modo liga se detecta', torneo_es_liga($ligaConFases), true);
check('modo copa no es liga', torneo_es_liga($campeonato), false);
check('copa sin modo guardado sigue siendo campeonato', torneo_es_liga($copaVieja), false);
// Clave: aunque queden fases guardadas de cuando era campeonato, una liga no muestra ninguna.
check('una liga nunca expone fases de playoff', torneo_fases_playoff($ligaConFases), []);
check('un campeonato conserva sus fases', torneo_fases_playoff($campeonato), ['cuartos', 'final']);
check('copa vieja conserva sus fases', torneo_fases_playoff($copaVieja), ['semifinal', 'final']);

// ---------------------------------------------------------------------------
echo "\n== Alineación: titulares según la modalidad y posiciones por deporte ==\n";

check('fútbol 11 juega con 11', torneo_jugadores_en_cancha(['deporte' => 'futbol', 'modalidad' => 'futbol11']), 11);
check('fútbol 7 juega con 7', torneo_jugadores_en_cancha(['deporte' => 'futbol', 'modalidad' => 'futbol7']), 7);
check('fútbol sala juega con 5', torneo_jugadores_en_cancha(['deporte' => 'futbol', 'modalidad' => 'futbol5']), 5);
check('basketball juega con 5', torneo_jugadores_en_cancha(['deporte' => 'basketball', 'modalidad' => 'fiba']), 5);
check('copa vieja sin modalidad usa el default del deporte', torneo_jugadores_en_cancha(['deporte' => 'futbol']), 11);

check('posición de fútbol con nombre largo', posicion_label('futbol', 'portero'), 'Portero');
check('posición de fútbol abreviada', posicion_label('futbol', 'portero', true), 'POR');
check('posición de basketball abreviada', posicion_label('basketball', 'pivot', true), 'P');
check('posición vacía se lee "Sin posición"', posicion_label('futbol', ''), 'Sin posición');
check('posición inexistente en el deporte no revienta', posicion_label('basketball', 'portero', true), '—');

// Titulares y banca de un equipo, ordenados por posición (portero primero) y luego dorsal.
$plantillaPrueba = jugadores_por_id([
    ['id' => 1, 'dorsal' => '10', 'nombre' => 'Ana', 'equipo_id' => 7],
    ['id' => 2, 'dorsal' => '1', 'nombre' => 'Bea', 'equipo_id' => 7],
    ['id' => 3, 'dorsal' => '5', 'nombre' => 'Caro', 'equipo_id' => 7],
    ['id' => 4, 'dorsal' => '9', 'nombre' => 'Dani', 'equipo_id' => 8],
]);
$alineacionPrueba = [
    ['jugador_id' => 1, 'equipo_id' => 7, 'titular' => true, 'posicion' => 'delantero'],
    ['jugador_id' => 2, 'equipo_id' => 7, 'titular' => true, 'posicion' => 'portero'],
    ['jugador_id' => 3, 'equipo_id' => 7, 'titular' => false, 'posicion' => 'defensa'],
    ['jugador_id' => 4, 'equipo_id' => 8, 'titular' => true, 'posicion' => 'delantero'],
];
$titulares7 = alineacion_de_equipo($alineacionPrueba, $plantillaPrueba, 7, true, 'futbol');
check('titulares del equipo 7: solo los suyos', count($titulares7), 2);
check('la portera va primero, aunque no tenga el dorsal más bajo del orden natural', $titulares7[0]['jugador']['nombre'], 'Bea');
$banca7 = alineacion_de_equipo($alineacionPrueba, $plantillaPrueba, 7, false, 'futbol');
check('banca del equipo 7', array_column(array_column($banca7, 'jugador'), 'nombre'), ['Caro']);
$alineacionHuerfana = [['jugador_id' => 99, 'equipo_id' => 7, 'titular' => true, 'posicion' => 'defensa']];
check('un jugador borrado de la plantilla no rompe la alineación', alineacion_de_equipo($alineacionHuerfana, $plantillaPrueba, 7, true, 'futbol'), []);

// ---------------------------------------------------------------------------
echo "\n== Generador de calendario (todos contra todos) ==\n";

/**
 * Resume un fixture para poder afirmar cosas sobre él: cuántas veces se enfrenta cada
 * par, cuántas veces juega cada equipo, si alguien se repite dentro de una jornada y
 * cuántos partidos de local le tocan a cada uno.
 */
function resumir_fixture(array $jornadas): array
{
    $cruces = [];
    $partidosPorEquipo = [];
    $localiasPorEquipo = [];
    $repiteEnJornada = false;

    foreach ($jornadas as $cruces_jornada) {
        $vistosEnJornada = [];
        foreach ($cruces_jornada as [$local, $visitante]) {
            $clave = min($local, $visitante) . '-' . max($local, $visitante);
            $cruces[$clave] = ($cruces[$clave] ?? 0) + 1;
            foreach ([$local, $visitante] as $eq) {
                $partidosPorEquipo[$eq] = ($partidosPorEquipo[$eq] ?? 0) + 1;
                if (isset($vistosEnJornada[$eq])) {
                    $repiteEnJornada = true;
                }
                $vistosEnJornada[$eq] = true;
            }
            $localiasPorEquipo[$local] = ($localiasPorEquipo[$local] ?? 0) + 1;
        }
    }

    return [
        'cruces' => $cruces,
        'partidos_por_equipo' => $partidosPorEquipo,
        'localias' => $localiasPorEquipo,
        'repite_en_jornada' => $repiteEnJornada,
    ];
}

// --- Par de equipos: el caso mínimo ---
$fixture2 = generar_fixture_round_robin([1, 2], 1);
check('2 equipos, una vuelta: 1 jornada', count($fixture2), 1);
check('2 equipos, una vuelta: 1 encuentro', count($fixture2[0]), 1);

// --- 8 equipos, una vuelta (par) ---
$fixture8 = generar_fixture_round_robin([1, 2, 3, 4, 5, 6, 7, 8], 1);
$r8 = resumir_fixture($fixture8);
check('8 equipos: 7 jornadas', count($fixture8), 7);
check('8 equipos: 28 encuentros', array_sum($r8['cruces']), 28);
check('8 equipos: cada par se enfrenta exactamente una vez', array_unique(array_values($r8['cruces'])), [1]);
check('8 equipos: nadie juega dos veces la misma jornada', $r8['repite_en_jornada'], false);
check('8 equipos: todos juegan 7 partidos', array_unique(array_values($r8['partidos_por_equipo'])), [7]);
check('8 equipos: 4 encuentros por jornada', array_unique(array_map('count', $fixture8)), [4]);
// Con 7 partidos por equipo la localía no puede ser exacta, pero sí repartida (3 o 4).
sort($r8['localias']);
$localias8 = array_values($r8['localias']);
check('8 equipos: la localía queda repartida (nadie con 0 ni con 7 de local)', min($localias8) >= 3 && max($localias8) <= 4, true);

// --- 5 equipos, una vuelta (impar: alguien descansa cada jornada) ---
$fixture5 = generar_fixture_round_robin([10, 20, 30, 40, 50], 1);
$r5 = resumir_fixture($fixture5);
check('5 equipos: 5 jornadas (una de descanso para cada uno)', count($fixture5), 5);
check('5 equipos: 10 encuentros', array_sum($r5['cruces']), 10);
check('5 equipos: cada par se enfrenta una vez', array_unique(array_values($r5['cruces'])), [1]);
check('5 equipos: 2 encuentros por jornada (el quinto descansa)', array_unique(array_map('count', $fixture5)), [2]);
check('5 equipos: todos juegan 4 partidos', array_unique(array_values($r5['partidos_por_equipo'])), [4]);
check('5 equipos: nadie juega dos veces la misma jornada', $r5['repite_en_jornada'], false);

// --- Ida y vuelta ---
$fixture6doble = generar_fixture_round_robin([1, 2, 3, 4, 5, 6], 2);
$r6 = resumir_fixture($fixture6doble);
check('6 equipos ida y vuelta: 10 jornadas', count($fixture6doble), 10);
check('6 equipos ida y vuelta: 30 encuentros', array_sum($r6['cruces']), 30);
check('6 equipos ida y vuelta: cada par se enfrenta dos veces', array_unique(array_values($r6['cruces'])), [2]);
check('6 equipos ida y vuelta: todos juegan 10 partidos', array_unique(array_values($r6['partidos_por_equipo'])), [10]);
// Lo que hace que la vuelta sea "vuelta": cada equipo juega la mitad de local y la mitad
// de visitante, porque el partido de regreso invierte la cancha.
check('6 equipos ida y vuelta: localía perfectamente pareja (5 y 5)', array_unique(array_values($r6['localias'])), [5]);
check('6 equipos ida y vuelta: nadie juega dos veces la misma jornada', $r6['repite_en_jornada'], false);

// Cada cruce de la ida aparece invertido en la vuelta.
$idaSolo = generar_fixture_round_robin([1, 2, 3, 4], 1);
$idaYVuelta = generar_fixture_round_robin([1, 2, 3, 4], 2);
$parJornada1Ida = $idaYVuelta[0][0];
$parMismaJornadaVuelta = $idaYVuelta[count($idaSolo)][0];
check('la vuelta invierte local y visitante del mismo cruce', $parMismaJornadaVuelta, [$parJornada1Ida[1], $parJornada1Ida[0]]);

// --- Casos borde ---
check('menos de 2 equipos no genera nada', generar_fixture_round_robin([1], 1), []);
check('sin equipos no genera nada', generar_fixture_round_robin([], 2), []);
check('equipos duplicados se ignoran (3 y 3 son el mismo)', count(generar_fixture_round_robin([1, 2, 3, 3], 1)), 3);
check('vueltas inválidas caen a una vuelta', count(generar_fixture_round_robin([1, 2, 3, 4], 0)), 3);

// --- Resumen previo que se le muestra al organizador antes de generar ---
check('resumen 10 equipos una vuelta: 45 encuentros', fixture_resumen(10, 1)['partidos'], 45);
check('resumen 10 equipos ida y vuelta: 90 encuentros', fixture_resumen(10, 2)['partidos'], 90);
check('resumen 10 equipos ida y vuelta: 18 jornadas', fixture_resumen(10, 2)['jornadas'], 18);
check('resumen avisa que con impares alguien descansa', fixture_resumen(7, 1)['descansa'], true);
check('resumen con pares: nadie descansa', fixture_resumen(8, 1)['descansa'], false);
check('resumen con menos de 2 equipos: nada que generar', fixture_resumen(1, 2), ['jornadas' => 0, 'partidos' => 0, 'descansa' => false]);
// El resumen mostrado y el fixture real tienen que coincidir, o el aviso mentiría.
check('el resumen coincide con el fixture real (9 equipos, ida y vuelta)', [
    count(generar_fixture_round_robin(range(1, 9), 2)),
    array_sum(resumir_fixture(generar_fixture_round_robin(range(1, 9), 2))['cruces']),
], [fixture_resumen(9, 2)['jornadas'], fixture_resumen(9, 2)['partidos']]);

check('vueltas del torneo: 2 se respeta', torneo_vueltas(['vueltas' => 2]), 2);
check('vueltas del torneo: copa vieja sin el campo juega una vuelta', torneo_vueltas([]), 1);
check('vueltas del torneo: valor raro cae a una vuelta', torneo_vueltas(['vueltas' => 7]), 1);

// ---------------------------------------------------------------------------
echo "\n== Voleibol (reglas FIVB) ==\n";
$v5 = ['deporte' => 'voleibol', 'modalidad' => 'voley6_5'];
$v3 = ['deporte' => 'voleibol', 'modalidad' => 'voley6_3'];
$playa = ['deporte' => 'voleibol', 'modalidad' => 'playa'];

check('set 25-23 es válido', voleibol_set_valido(25, 23, 25), true);
check('set 25-24 no es válido (falta diferencia de 2)', voleibol_set_valido(25, 24, 25), false);
check('set 26-24 es válido (deuce)', voleibol_set_valido(26, 24, 25), true);
check('set 30-27 no es válido (pasado el deuce gana por 2 justo)', voleibol_set_valido(30, 27, 25), false);
check('set 24-20 no es válido (no llegó a 25)', voleibol_set_valido(24, 20, 25), false);
check('set decisivo 15-13 es válido', voleibol_set_valido(15, 13, 15), true);
check('set de playa 21-19 es válido', voleibol_set_valido(21, 19, 21), true);

$r = voleibol_validar_sets([[25, 20], [22, 25], [25, 18], [25, 21]], $v5);
check('3-1 a cinco sets: sin errores y terminado', [$r['errores'], $r['terminado'], $r['marcador']], [[], true, [3, 1]]);
$r = voleibol_validar_sets([[25, 20], [22, 25], [25, 18], [20, 25], [15, 12]], $v5);
check('3-2 con quinto set a 15: válido', [$r['errores'], $r['marcador']], [[], [3, 2]]);
$r = voleibol_validar_sets([[25, 20], [22, 25], [25, 18], [20, 25], [25, 15]], $v5);
check('quinto set 25-15 no es válido (el decisivo se cierra en 15)', count($r['errores']), 1);
$r = voleibol_validar_sets([[25, 20], [25, 18], [25, 21], [25, 10]], $v5);
check('un set de más después de 3-0 es error', count($r['errores']), 1);
$r = voleibol_validar_sets([[25, 20], [22, 25]], $v5);
check('partido a medias: sin errores pero no terminado', [$r['errores'], $r['terminado']], [[], false]);
$r = voleibol_validar_sets([[25, 20], [20, 25], [15, 10]], $v3);
check('a tres sets: el tercero es el decisivo a 15', [$r['errores'], $r['terminado'], $r['marcador']], [[], true, [2, 1]]);
$r = voleibol_validar_sets([[21, 18], [21, 19]], $playa);
check('playa: 2-0 con sets a 21', [$r['errores'], $r['terminado']], [[], true]);

check('puntos FIVB 3-0: 3 y 0', voleibol_puntos_tabla(3, 0), [3, 0]);
check('puntos FIVB 3-1: 3 y 0', voleibol_puntos_tabla(3, 1), [3, 0]);
check('puntos FIVB 3-2: 2 y 1', voleibol_puntos_tabla(3, 2), [2, 1]);
check('puntos FIVB 2-1 (a tres sets): 2 y 1', voleibol_puntos_tabla(2, 1), [2, 1]);
check('puntos FIVB por default: 3 y 0', voleibol_puntos_tabla(3, 0, true), [3, 0]);
check('texto de sets ida y vuelta', voleibol_sets_a_texto(voleibol_parsear_sets('25-20, 22-25,basura,15-13')), '25-20,22-25,15-13');
check('W.O. de voleibol a cinco sets es 3-0', marcador_por_default('voleibol', $v5), [3, 0]);
check('W.O. de voleibol a tres sets es 2-0', marcador_por_default('voleibol', $v3), [2, 0]);
check('voleibol no tiene expulsión por acumular amarillas', limite_faltas_expulsion('voleibol') > 100, true);
check('posiciones de voleibol incluyen el líbero', isset(posiciones_catalogo('voleibol')['libero']), true);
check('6 en cancha en voleibol 6x6', torneo_jugadores_en_cancha($v5), 6);
check('2 en cancha en voleibol de playa', torneo_jugadores_en_cancha($playa), 2);
check('fútbol sigue con sus posiciones', isset(posiciones_catalogo('futbol')['portero']), true);
check('un deporte desconocido sigue tratándose como fútbol', deporte_clave('ajedrez'), 'futbol');

// Tabla FIVB: A gana 3-0 a B y 3-2 a C; B gana 3-1 a C.
// A: 2 ganados, 3+2 = 5 pts. B: 1 ganado, 0+3 = 3 pts. C: 0 ganados, 1+0 = 1 pt.
$eqV = [['id' => 1, 'nombre' => 'A'], ['id' => 2, 'nombre' => 'B'], ['id' => 3, 'nombre' => 'C']];
$pV = [
    ['id' => 1, 'equipo_local' => 1, 'equipo_visitante' => 2, 'marcador_local' => 3, 'marcador_visitante' => 0, 'estado' => 'jugado', 'fase' => 'grupos', 'fecha' => '2026-01-01', 'hora' => '10:00', 'sets' => '25-20,25-20,25-20'],
    ['id' => 2, 'equipo_local' => 1, 'equipo_visitante' => 3, 'marcador_local' => 3, 'marcador_visitante' => 2, 'estado' => 'jugado', 'fase' => 'grupos', 'fecha' => '2026-01-02', 'hora' => '10:00', 'sets' => '25-20,20-25,25-20,20-25,15-13'],
    ['id' => 3, 'equipo_local' => 2, 'equipo_visitante' => 3, 'marcador_local' => 3, 'marcador_visitante' => 1, 'estado' => 'jugado', 'fase' => 'grupos', 'fecha' => '2026-01-03', 'hora' => '10:00', 'sets' => '25-20,25-20,20-25,25-20'],
];
$tV = calcular_tabla($eqV, $pV, $v5 + ['permite_empates' => true]);
check('tabla FIVB: orden A, B, C', array_map(fn($f) => $f['equipo']['nombre'], $tV), ['A', 'B', 'C']);
check('tabla FIVB: puntos 5, 3, 1', array_map(fn($f) => $f['pts'], $tV), [5, 3, 1]);
check('tabla FIVB: el voleibol ignora "permite empates"', array_sum(array_map(fn($f) => $f['pe'], $tV)), 0);
check('tabla FIVB: sets a favor y en contra de A', [$tV[0]['pf'], $tV[0]['pc']], [6, 2]);
check('tabla FIVB: cociente de sets de A', $tV[0]['cociente_sets'], 3.0);

// Desempate: X y Y con 1 ganado y 3 pts cada uno; X tiene mejor cociente de sets.
$eqD = [['id' => 1, 'nombre' => 'X'], ['id' => 2, 'nombre' => 'Y'], ['id' => 3, 'nombre' => 'Z'], ['id' => 4, 'nombre' => 'W']];
$pD = [
    ['id' => 1, 'equipo_local' => 1, 'equipo_visitante' => 3, 'marcador_local' => 3, 'marcador_visitante' => 0, 'estado' => 'jugado', 'fase' => 'grupos', 'fecha' => '2026-01-01', 'hora' => '', 'sets' => '25-10,25-10,25-10'],
    ['id' => 2, 'equipo_local' => 2, 'equipo_visitante' => 4, 'marcador_local' => 3, 'marcador_visitante' => 1, 'estado' => 'jugado', 'fase' => 'grupos', 'fecha' => '2026-01-01', 'hora' => '', 'sets' => '25-10,10-25,25-10,25-10'],
];
$tD = calcular_tabla($eqD, $pD, $v5);
check('desempate FIVB por cociente de sets: X antes que Y', [$tD[0]['equipo']['nombre'], $tD[1]['equipo']['nombre']], ['X', 'Y']);

// ---------------------------------------------------------------------------
echo "\n== Inscripción y carnet ==\n";
check('documento con espacios y guiones se normaliza', documento_normalizar(' 2345 67890-0101 '), '2345678900101');
check('DPI de 13 dígitos es válido', documento_error('2345678900101'), '');
check('pasaporte con letras es válido', documento_error('GT1234567'), '');
check('documento vacío no se acepta', documento_error('') !== '', true);
check('documento con símbolos no se acepta', documento_error('ABC#123') !== '', true);
check('fecha de nacimiento válida', fecha_nacimiento_error('2001-05-04'), '');
check('fecha de nacimiento futura no', fecha_nacimiento_error(date('Y-m-d', strtotime('+1 day'))) !== '', true);
check('fecha imposible (31 de febrero) no', fecha_nacimiento_error('2001-02-31') !== '', true);
check('jugador viejo sin estado cuenta como inscrito', jugador_inscrito(['activo' => true]), true);
check('pendiente no está inscrito', jugador_inscrito(['inscripcion_estado' => 'pendiente']), false);
check('pendiente activo no puede alinearse', jugador_habilitado_plantilla(['activo' => true, 'inscripcion_estado' => 'pendiente']), false);
check('faltan datos para aprobar', inscripcion_faltante(['documento' => '', 'fecha_nacimiento' => '2001-01-01', 'foto' => '']), 'documento, foto');
$copaCarnet = [
    ['id' => 1, 'equipo_id' => 1, 'documento' => '2345678900101', 'carnet_numero' => 'VOL-2026-0007', 'carnet_token' => str_repeat('a', 24)],
    ['id' => 2, 'equipo_id' => 2, 'documento' => '', 'carnet_numero' => '', 'carnet_token' => ''],
    ['id' => 3, 'equipo_id' => 2, 'documento' => '', 'carnet_numero' => '', 'carnet_token' => ''],
];
check('el mismo documento en otro equipo se detecta', jugador_con_documento($copaCarnet, '2345678900101', 2)['id'] ?? null, 1);
check('el propio jugador no es su duplicado', jugador_con_documento($copaCarnet, '2345678900101', 1), null);
check('se asignan dos carnets', carnet_asignar($copaCarnet, [2, 3], ['deporte' => 'voleibol', 'temporada' => '2026']), 2);
check('numeración correlativa sigue al mayor', [$copaCarnet[1]['carnet_numero'], $copaCarnet[2]['carnet_numero']], ['VOL-2026-0008', 'VOL-2026-0009']);
check('el código del QR es aleatorio de 24 caracteres', (bool) preg_match('/^[a-f0-9]{24}$/', $copaCarnet[1]['carnet_token']), true);
check('un número ya asignado no cambia', carnet_asignar($copaCarnet, [1], ['deporte' => 'voleibol', 'temporada' => '2026']), 0);
check('se encuentra el jugador por el código del QR', jugador_por_carnet_token($copaCarnet, str_repeat('a', 24))['id'] ?? null, 1);
check('un código con formato raro no busca nada', jugador_por_carnet_token($copaCarnet, "' OR 1=1 --"), null);

// ---------------------------------------------------------------------------
echo "\n============================\n";
printf("%d pruebas, %d fallos\n", $pruebas, $fallos);
echo $fallos === 0 ? "TODAS LAS PRUEBAS PASARON\n" : "HAY FALLOS - revisar antes de desplegar\n";
exit($fallos === 0 ? 0 : 1);
