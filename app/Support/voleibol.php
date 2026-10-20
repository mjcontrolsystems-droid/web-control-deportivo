<?php
declare(strict_types=1);

/**
 * Reglas del voleibol según la FIVB.
 *
 * En voleibol no hay reloj: el partido se gana por sets y cada set por puntos.
 *   - Set normal a 25 puntos (21 en playa) con diferencia de 2, sin tope.
 *   - Set decisivo (el 5.º a cinco sets, el 3.º a tres) a 15 con diferencia de 2.
 *   - El resultado del partido son los SETS ganados (3-1, 2-0...). Eso es lo que se guarda
 *     en marcador_local / marcador_visitante, así la tabla, los playoffs y el podio
 *     funcionan igual que en los otros deportes sin tocarlos.
 *   - El detalle de cada set ("25-20,22-25,25-18,25-21") va en partidos.sets.
 *
 * Tabla de posiciones (FIVB):
 *   - Ganar 3-0 o 3-1: 3 puntos al ganador, 0 al perdedor.
 *   - Ganar 3-2: 2 puntos al ganador, 1 al perdedor.
 *   - Orden: partidos ganados, puntos, cociente de sets, cociente de puntos.
 */

const VOLEIBOL_REGLAS = [
    'voley6_5' => ['sets_para_ganar' => 3, 'puntos_set' => 25, 'puntos_decisivo' => 15],
    'voley6_3' => ['sets_para_ganar' => 2, 'puntos_set' => 25, 'puntos_decisivo' => 15],
    'playa' => ['sets_para_ganar' => 2, 'puntos_set' => 21, 'puntos_decisivo' => 15],
];

/**
 * Reglas de sets de la copa según su modalidad (la de cinco sets si no declaró ninguna).
 *
 * @return array{sets_para_ganar: int, puntos_set: int, puntos_decisivo: int}
 */
function voleibol_reglas(array $torneo): array
{
    $modalidad = (string) ($torneo['modalidad'] ?? '');

    return VOLEIBOL_REGLAS[$modalidad] ?? VOLEIBOL_REGLAS['voley6_5'];
}

function voleibol_sets_para_ganar(array $torneo): int
{
    return voleibol_reglas($torneo)['sets_para_ganar'];
}

/**
 * Máximo de sets que puede durar un partido (5 a cinco sets, 3 a tres).
 */
function voleibol_sets_maximos(array $torneo): int
{
    return voleibol_sets_para_ganar($torneo) * 2 - 1;
}

/**
 * Sets de un partido como lista de [local, visitante], leídos de partidos.sets.
 *
 * @return array<int, array{0: int, 1: int}>
 */
function voleibol_sets_de_partido(array $partido): array
{
    return voleibol_parsear_sets((string) ($partido['sets'] ?? ''));
}

/**
 * "25-20,22-25" -> [[25, 20], [22, 25]]. Ignora lo que no tenga forma de set.
 *
 * @return array<int, array{0: int, 1: int}>
 */
function voleibol_parsear_sets(string $texto): array
{
    $sets = [];
    foreach (explode(',', $texto) as $parte) {
        if (preg_match('/^\s*(\d{1,3})\s*-\s*(\d{1,3})\s*$/', $parte, $m)) {
            $sets[] = [(int) $m[1], (int) $m[2]];
        }
    }

    return $sets;
}

/**
 * [[25, 20], [22, 25]] -> "25-20,22-25", el formato en que se guarda.
 */
function voleibol_sets_a_texto(array $sets): string
{
    return implode(',', array_map(fn($s) => (int) $s[0] . '-' . (int) $s[1], $sets));
}

/**
 * Sets ganados por cada lado: es el marcador del partido.
 *
 * @return array{0: int, 1: int}
 */
function voleibol_sets_ganados(array $sets): array
{
    $local = 0;
    $visitante = 0;
    foreach ($sets as [$a, $b]) {
        if ($a > $b) {
            $local++;
        } elseif ($b > $a) {
            $visitante++;
        }
    }

    return [$local, $visitante];
}

/**
 * Puntos totales (de juego) de cada lado, para el cociente de puntos de la tabla.
 *
 * @return array{0: int, 1: int}
 */
function voleibol_puntos_totales(array $sets): array
{
    return [array_sum(array_column($sets, 0)), array_sum(array_column($sets, 1))];
}

/**
 * ¿Es un resultado posible para un set terminado? A 25 (o 15 en el decisivo): el ganador
 * llega justo al objetivo con 2 o más de ventaja, o, si hubo "deuce", gana por exactamente 2.
 */
function voleibol_set_valido(int $a, int $b, int $objetivo): bool
{
    $ganador = max($a, $b);
    $perdedor = min($a, $b);
    if ($perdedor <= $objetivo - 2) {
        return $ganador === $objetivo;
    }

    return $ganador === $perdedor + 2;
}

/**
 * Revisa una lista de sets contra las reglas de la copa.
 *
 * Devuelve los errores (vacío si todo está bien) y si el partido ya quedó definido: alguien
 * llegó a los sets que hacen falta para ganar.
 *
 * @return array{errores: array<int, string>, terminado: bool, marcador: array{0: int, 1: int}}
 */
function voleibol_validar_sets(array $sets, array $torneo): array
{
    $reglas = voleibol_reglas($torneo);
    $paraGanar = $reglas['sets_para_ganar'];
    $maximos = $paraGanar * 2 - 1;
    $errores = [];
    $local = 0;
    $visitante = 0;

    foreach (array_values($sets) as $i => [$a, $b]) {
        $numero = $i + 1;
        if ($local === $paraGanar || $visitante === $paraGanar) {
            $errores[] = "El set {$numero} sobra: el partido ya estaba definido.";
            break;
        }
        if ($numero > $maximos) {
            $errores[] = "Este formato se juega a un máximo de {$maximos} sets.";
            break;
        }
        $objetivo = $numero === $maximos ? $reglas['puntos_decisivo'] : $reglas['puntos_set'];
        if ($a === $b || !voleibol_set_valido($a, $b, $objetivo)) {
            $errores[] = "El set {$numero} ({$a}-{$b}) no es un resultado posible: se gana a {$objetivo} con 2 puntos de diferencia.";
            continue;
        }
        $a > $b ? $local++ : $visitante++;
    }

    return [
        'errores' => $errores,
        'terminado' => empty($errores) && ($local === $paraGanar || $visitante === $paraGanar),
        'marcador' => [$local, $visitante],
    ];
}

/**
 * Puntos de tabla FIVB para [ganador, perdedor] según cómo terminó en sets.
 * Ganar sin llegar al set decisivo vale 3-0; ganar en el decisivo, 2-1.
 *
 * @return array{0: int, 1: int}
 */
function voleibol_puntos_tabla(int $setsGanador, int $setsPerdedor, bool $porDefault = false): array
{
    if ($porDefault) {
        return [3, 0];
    }

    return $setsPerdedor === $setsGanador - 1 ? [2, 1] : [3, 0];
}

/**
 * Detalle de sets para mostrar: "25-20 · 22-25 · 25-18".
 */
function voleibol_texto_sets(array $partido, string $separador = ' · '): string
{
    return implode($separador, array_map(fn($s) => $s[0] . '-' . $s[1], voleibol_sets_de_partido($partido)));
}

/**
 * Motivo por el que un partido de voleibol todavía no se puede dar por jugado ('' si sí se
 * puede): sus sets tienen que ser válidos y alguien tiene que haber llegado a los sets
 * que hacen falta para ganar. Un W.O. no necesita sets.
 */
function voleibol_error_al_cerrar(array $partido, array $torneo): string
{
    if (!empty($partido['por_default'])) {
        return '';
    }
    $revision = voleibol_validar_sets(voleibol_sets_de_partido($partido), $torneo);
    if (!empty($revision['errores'])) {
        return $revision['errores'][0];
    }
    if (!$revision['terminado']) {
        $paraGanar = voleibol_sets_para_ganar($torneo);
        return "El partido todavía no tiene ganador: se gana con {$paraGanar} sets. Carga el resultado de cada set en la ficha del partido.";
    }

    return '';
}

/**
 * Cociente FIVB para mostrar: "1.250", o "MAX" cuando no hay nada en contra (división por
 * cero; calcular_tabla() lo guarda como 999 para poder ordenar).
 */
function voleibol_formato_cociente(float $cociente): string
{
    return $cociente >= 999 ? 'MAX' : number_format($cociente, 3);
}

/**
 * Qué cuenta el marcador del partido: sets en voleibol; goles o puntos en los demás.
 */
function etiqueta_unidad_marcador(?string $deporte): string
{
    return es_voleibol($deporte) ? 'Sets' : etiqueta_anotaciones($deporte);
}
