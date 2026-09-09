<?php
declare(strict_types=1);

/**
 * Suspensiones por tarjetas.
 *
 * Es la regla que decide quién NO puede entrar a la cancha el fin de semana. Se calcula
 * en caliente desde las tarjetas registradas (no se guarda "suspendido hasta X"), así que
 * cualquier cambio en la ficha de un partido viejo recalcula todo. Eso la hace flexible
 * y también fácil de romper sin darse cuenta.
 *
 * Lo que se fija: la roja se paga en el SIGUIENTE partido del equipo, el castigo se agota,
 * la acumulación de amarillas dispara en el múltiplo exacto y una copa que no configuró
 * suspensiones no suspende a nadie.
 */

grupo('Suspensiones por tarjetas');

// Liga típica: roja = 1 partido; cada 4 amarillas = 1 partido.
$torneo = [
    'partidos_suspension_roja' => 1,
    'amarillas_para_suspension' => 4,
    'partidos_suspension_amarillas' => 1,
];

// Cinco fechas seguidas del equipo 1 contra rivales distintos.
$partidos = [];
foreach ([1, 2, 3, 4, 5] as $i) {
    $partidos[] = [
        'id' => $i,
        'jornada' => $i,
        'equipo_local' => 1,
        'equipo_visitante' => 90 + $i,
        'fecha' => '2026-09-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT),
        'hora' => '09:00',
    ];
}
$porId = fn(int $id) => array_values(array_filter($partidos, fn($p) => $p['id'] === $id))[0];

$jugadores = [100 => ['id' => 100, 'equipo_id' => 1, 'nombre' => 'Kerwin']];

$tarjeta = fn(string $tipo, int $partidoId, int $jugador = 100) => [
    'tipo' => $tipo,
    'partido_id' => $partidoId,
    'jugador_id' => $jugador,
];

prueba('una roja suspende el siguiente partido del equipo', function () use ($torneo, $partidos, $jugadores, $tarjeta, $porId) {
    $castigos = disciplina_castigos_desde_eventos([$tarjeta('roja', 2)], $torneo, $partidos);
    igual(1, count($castigos[100]), 'un castigo por la roja');
    igual('roja', $castigos[100][0]['motivo']);

    $suspendidos = disciplina_suspendidos_desde_castigos($castigos, $porId(3), $partidos, $jugadores);
    cierto(isset($suspendidos[100]), 'no puede jugar el partido 3');
});

prueba('el castigo se agota: al segundo partido ya puede jugar', function () use ($torneo, $partidos, $jugadores, $tarjeta, $porId) {
    $castigos = disciplina_castigos_desde_eventos([$tarjeta('roja', 2)], $torneo, $partidos);
    $suspendidos = disciplina_suspendidos_desde_castigos($castigos, $porId(4), $partidos, $jugadores);
    falso(isset($suspendidos[100]), 'el partido 4 ya lo puede jugar');
});

prueba('el partido donde vio la roja sí lo jugó', function () use ($torneo, $partidos, $jugadores, $tarjeta, $porId) {
    // La expulsión es de ese partido; la suspensión empieza en el siguiente. Marcarlo
    // como suspendido en el propio partido dejaría la ficha inconsistente.
    $castigos = disciplina_castigos_desde_eventos([$tarjeta('roja', 2)], $torneo, $partidos);
    $suspendidos = disciplina_suspendidos_desde_castigos($castigos, $porId(2), $partidos, $jugadores);
    falso(isset($suspendidos[100]));
});

prueba('una roja de dos partidos cubre los dos siguientes', function () use ($partidos, $jugadores, $tarjeta, $porId) {
    $torneoDuro = ['partidos_suspension_roja' => 2, 'amarillas_para_suspension' => 0, 'partidos_suspension_amarillas' => 0];
    $castigos = disciplina_castigos_desde_eventos([$tarjeta('roja', 1)], $torneoDuro, $partidos);

    cierto(isset(disciplina_suspendidos_desde_castigos($castigos, $porId(2), $partidos, $jugadores)[100]), 'partido 2');
    cierto(isset(disciplina_suspendidos_desde_castigos($castigos, $porId(3), $partidos, $jugadores)[100]), 'partido 3');
    falso(isset(disciplina_suspendidos_desde_castigos($castigos, $porId(4), $partidos, $jugadores)[100]), 'partido 4 ya no');
});

prueba('la cuarta amarilla dispara la suspensión, la tercera no', function () use ($torneo, $partidos, $jugadores, $tarjeta) {
    $tres = disciplina_castigos_desde_eventos(
        [$tarjeta('amarilla', 1), $tarjeta('amarilla', 2), $tarjeta('amarilla', 3)],
        $torneo,
        $partidos
    );
    igual([], $tres, 'con tres amarillas todavía no hay castigo');

    $cuatro = disciplina_castigos_desde_eventos(
        [$tarjeta('amarilla', 1), $tarjeta('amarilla', 2), $tarjeta('amarilla', 3), $tarjeta('amarilla', 4)],
        $torneo,
        $partidos
    );
    igual(1, count($cuatro[100]), 'la cuarta sí');
    igual('amarillas', $cuatro[100][0]['motivo']);
    igual(4, $cuatro[100][0]['partido_id'], 'el castigo nace en el partido de la cuarta');
});

prueba('la acumulación de amarillas se paga en el siguiente partido', function () use ($torneo, $partidos, $jugadores, $tarjeta, $porId) {
    $castigos = disciplina_castigos_desde_eventos(
        [$tarjeta('amarilla', 1), $tarjeta('amarilla', 2), $tarjeta('amarilla', 3), $tarjeta('amarilla', 4)],
        $torneo,
        $partidos
    );
    falso(isset(disciplina_suspendidos_desde_castigos($castigos, $porId(4), $partidos, $jugadores)[100]), 'el 4 lo jugó');
    cierto(isset(disciplina_suspendidos_desde_castigos($castigos, $porId(5), $partidos, $jugadores)[100]), 'el 5 no');
});

prueba('el contador de amarillas NO se reinicia por partido', function () use ($torneo, $partidos, $tarjeta) {
    // Cuatro amarillas repartidas en cuatro fechas distintas cuentan igual que cuatro
    // seguidas: la acumulación es de toda la temporada.
    $castigos = disciplina_castigos_desde_eventos(
        [$tarjeta('amarilla', 4), $tarjeta('amarilla', 1), $tarjeta('amarilla', 3), $tarjeta('amarilla', 2)],
        $torneo,
        $partidos
    );
    igual(1, count($castigos[100]), 'el orden en que llegan los eventos no cambia el resultado');
    igual(4, $castigos[100][0]['partido_id'], 'se ordenan por fecha, no por orden de captura');
});

prueba('en un doblete, la roja del sábado bloquea el domingo de la MISMA jornada', function () use ($torneo, $jugadores, $tarjeta) {
    // El caso real de esta liga: cuando el cupo del fin de semana da para más de una
    // ronda, hay equipos que juegan dos veces la misma jornada. La suspensión se cuenta
    // por PARTIDOS del equipo y no por jornadas, así que el rojo del primer encuentro
    // tiene que pesar en el segundo aunque los dos digan "jornada 3".
    $doblete = [
        ['id' => 1, 'jornada' => 3, 'equipo_local' => 1, 'equipo_visitante' => 90, 'fecha' => '2026-09-05', 'hora' => '15:00'],
        ['id' => 2, 'jornada' => 3, 'equipo_local' => 1, 'equipo_visitante' => 91, 'fecha' => '2026-09-06', 'hora' => '16:00'],
        ['id' => 3, 'jornada' => 4, 'equipo_local' => 1, 'equipo_visitante' => 92, 'fecha' => '2026-09-12', 'hora' => '15:00'],
    ];
    $porIdDoblete = fn(int $id) => array_values(array_filter($doblete, fn($p) => $p['id'] === $id))[0];

    $castigos = disciplina_castigos_desde_eventos([$tarjeta('roja', 1)], $torneo, $doblete);

    cierto(isset(disciplina_suspendidos_desde_castigos($castigos, $porIdDoblete(2), $doblete, $jugadores)[100]),
        'el segundo partido de la jornada 3 lo pierde');
    falso(isset(disciplina_suspendidos_desde_castigos($castigos, $porIdDoblete(3), $doblete, $jugadores)[100]),
        'la jornada 4 ya la puede jugar: cumplió con el domingo');
});

prueba('una copa sin suspensiones configuradas no suspende a nadie', function () use ($partidos, $tarjeta) {
    $sinReglas = ['partidos_suspension_roja' => 0, 'amarillas_para_suspension' => 0, 'partidos_suspension_amarillas' => 0];
    igual([], disciplina_castigos_desde_eventos([$tarjeta('roja', 1)], $sinReglas, $partidos));
});

grupo('Aviso de que va a la próxima amarilla');

prueba('con una amarilla todavía no se avisa nada', function () use ($torneo, $partidos, $tarjeta) {
    // Avisar "va 1 de 4" es ruido: nadie cambia nada por eso.
    $r = disciplina_acumulacion_desde_eventos([$tarjeta('amarilla', 1)], $torneo, $partidos);
    igual(1, $r[100]['amarillas']);
    falso($r[100]['al_borde']);
});

prueba('a una de la suspensión sí se avisa', function () use ($torneo, $partidos, $tarjeta) {
    // Con 4 amarillas por suspensión, el aviso salta en la tercera.
    $r = disciplina_acumulacion_desde_eventos(
        [$tarjeta('amarilla', 1), $tarjeta('amarilla', 2), $tarjeta('amarilla', 3)],
        $torneo,
        $partidos
    );
    igual(3, $r[100]['hacia_suspension']);
    igual(1, $r[100]['faltan']);
    cierto($r[100]['al_borde']);
});

prueba('al cumplir el múltiplo el contador vuelve a empezar', function () use ($torneo, $partidos, $tarjeta) {
    // La cuarta amarilla ya suspendió. Seguir diciendo "está al borde" sería mentira: le
    // vuelven a faltar cuatro para la siguiente.
    $r = disciplina_acumulacion_desde_eventos(
        [$tarjeta('amarilla', 1), $tarjeta('amarilla', 2), $tarjeta('amarilla', 3), $tarjeta('amarilla', 4)],
        $torneo,
        $partidos
    );
    igual(4, $r[100]['amarillas'], 'el total de la temporada no se pierde');
    igual(0, $r[100]['hacia_suspension'], 'pero hacia la próxima va en cero');
    falso($r[100]['al_borde']);
});

prueba('el aviso vuelve en la séptima', function () use ($torneo, $partidos, $tarjeta) {
    $eventos = [];
    foreach ([1, 2, 3, 4, 5] as $p) { $eventos[] = $tarjeta('amarilla', $p); }
    $eventos[] = $tarjeta('amarilla', 1);
    $eventos[] = $tarjeta('amarilla', 2);   // séptima
    $r = disciplina_acumulacion_desde_eventos($eventos, $torneo, $partidos);
    igual(7, $r[100]['amarillas']);
    igual(3, $r[100]['hacia_suspension'], '7 entre 4 deja 3');
    cierto($r[100]['al_borde']);
});

prueba('las rojas no cuentan para la acumulación', function () use ($torneo, $partidos, $tarjeta) {
    // La roja tiene su propio castigo; sumarla aquí lo cobraría dos veces.
    $r = disciplina_acumulacion_desde_eventos([$tarjeta('roja', 1), $tarjeta('roja', 2)], $torneo, $partidos);
    igual([], $r);
});

prueba('una liga que no suspende por acumulación no avisa nada', function () use ($partidos, $tarjeta) {
    $sinAcumulacion = ['partidos_suspension_roja' => 1, 'amarillas_para_suspension' => 0, 'partidos_suspension_amarillas' => 0];
    igual([], disciplina_acumulacion_desde_eventos([$tarjeta('amarilla', 1)], $sinAcumulacion, $partidos));
});

prueba('una tarjeta de un partido borrado no cuenta', function () use ($torneo, $partidos, $tarjeta) {
    $r = disciplina_acumulacion_desde_eventos([$tarjeta('amarilla', 999)], $torneo, $partidos);
    igual([], $r, 'el partido 999 no existe en el calendario');
});

grupo('Quién lleva más tarjetas');

prueba('ordena por total y desempata por rojas', function () use ($torneo, $partidos, $tarjeta) {
    // Cuatro tarjetas no son lo mismo si una es roja.
    $jugadores = [
        ['id' => 100, 'equipo_id' => 1, 'nombre' => 'Solo amarillas', 'dorsal' => '5'],
        ['id' => 200, 'equipo_id' => 1, 'nombre' => 'Con roja', 'dorsal' => '7'],
    ];
    $equipos = [1 => ['id' => 1, 'nombre' => 'Promo 45']];

    $eventos = [
        $tarjeta('amarilla', 1, 100), $tarjeta('amarilla', 2, 100),
        $tarjeta('amarilla', 1, 200), $tarjeta('roja', 2, 200),
    ];

    $r = disciplina_ranking_desde_eventos($eventos, $jugadores, $equipos, $torneo, $partidos);
    igual(2, count($r));
    igual(200, (int) $r[0]['jugador']['id'], 'con la misma cantidad, primero el de la roja');
    igual(1, $r[0]['rojas']);
    igual(2, $r[1]['amarillas']);
});

prueba('solo aparecen los que tienen tarjetas', function () use ($torneo, $partidos, $tarjeta) {
    // Una lista con los 240 jugadores casi todos en cero esconde a los que importan.
    $jugadores = [
        ['id' => 100, 'equipo_id' => 1, 'nombre' => 'Amonestado', 'dorsal' => '5'],
        ['id' => 200, 'equipo_id' => 1, 'nombre' => 'Impecable', 'dorsal' => '7'],
    ];
    $r = disciplina_ranking_desde_eventos([$tarjeta('amarilla', 1, 100)], $jugadores, [1 => ['id' => 1, 'nombre' => 'A']], $torneo, $partidos);
    igual(1, count($r));
    igual(100, (int) $r[0]['jugador']['id']);
});

prueba('una tarjeta sin jugador identificado no rompe el ranking', function () use ($torneo, $partidos) {
    // Se puede registrar una tarjeta sin decir a quién; no hay a quién rankear.
    $sinJugador = [['tipo' => 'amarilla', 'partido_id' => 1, 'jugador_id' => 0]];
    igual([], disciplina_ranking_desde_eventos($sinJugador, [], [], $torneo, $partidos));
});

prueba('el ranking por equipo suma lo de sus jugadores', function () use ($torneo, $partidos, $tarjeta) {
    $jugadores = [
        ['id' => 100, 'equipo_id' => 1, 'nombre' => 'Uno', 'dorsal' => '5'],
        ['id' => 200, 'equipo_id' => 1, 'nombre' => 'Dos', 'dorsal' => '7'],
        ['id' => 300, 'equipo_id' => 2, 'nombre' => 'Tres', 'dorsal' => '9'],
    ];
    $equipos = [1 => ['id' => 1, 'nombre' => 'Promo 45'], 2 => ['id' => 2, 'nombre' => 'Promo 52']];

    $eventos = [
        $tarjeta('amarilla', 1, 100), $tarjeta('roja', 2, 100),
        $tarjeta('amarilla', 1, 200),
        $tarjeta('amarilla', 1, 300),
    ];

    $porEquipo = disciplina_ranking_equipos(
        disciplina_ranking_desde_eventos($eventos, $jugadores, $equipos, $torneo, $partidos),
        $equipos
    );

    igual(2, count($porEquipo));
    igual(1, (int) $porEquipo[0]['equipo']['id'], 'el que más tarjetas tiene va primero');
    igual(3, $porEquipo[0]['total'], '2 amarillas y 1 roja');
    igual(2, $porEquipo[0]['jugadores'], 'dos jugadores amonestados');
    igual(1, $porEquipo[1]['total']);
});

grupo('Suspensiones por tarjetas (continuación)');

prueba('las tarjetas de otro jugador no salpican', function () use ($torneo, $partidos, $jugadores, $tarjeta, $porId) {
    $castigos = disciplina_castigos_desde_eventos([$tarjeta('roja', 1, 200)], $torneo, $partidos);
    $suspendidos = disciplina_suspendidos_desde_castigos($castigos, $porId(2), $partidos, $jugadores);
    falso(isset($suspendidos[100]), 'el 100 puede jugar: la roja fue del 200');
});
