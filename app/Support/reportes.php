<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Reportes del panel: tarjetas y multas, goles, resultados, pagos y jugadores.
//
// Cada reporte se arma UNA sola vez como datos (secciones con columnas y filas) y de ahí
// salen las tres formas de entregarlo: la tabla en pantalla, la hoja impresa y el Excel.
// Así lo que se ve, lo que se imprime y lo que se descarga nunca dicen cosas distintas.
//
// Funciones puras: reciben los datos ya leídos de la base y los filtros, y no tocan la
// base ni imprimen nada. Se prueban en scripts/tests.php.
// ---------------------------------------------------------------------------

/**
 * Catálogo de reportes. 'filtros' dice qué controles muestra el formulario para cada uno.
 */
const REPORTES_TIPOS = [
    'tarjetas' => [
        'nombre' => 'Tarjetas y multas',
        'icono' => 'bi-card-heading',
        'descripcion' => 'Cada tarjeta con su partido, la multa, si ya se pagó y en qué fecha.',
        'filtros' => ['equipo', 'fechas', 'estado', 'fecha_por'],
    ],
    'goles' => [
        'nombre' => 'Goles y anotaciones',
        'icono' => 'bi-bullseye',
        'descripcion' => 'Cada gol con su partido y minuto, la tabla de goleadores y el total por equipo.',
        'filtros' => ['equipo', 'fechas'],
    ],
    'resultados' => [
        'nombre' => 'Resultados de encuentros',
        'icono' => 'bi-calendar2-week',
        'descripcion' => 'Los partidos con fecha, cancha y marcador.',
        'filtros' => ['equipo', 'fechas', 'estado'],
    ],
    'pagos' => [
        'nombre' => 'Pagos y cuentas',
        'icono' => 'bi-wallet2',
        'descripcion' => 'Cargos y pagos de cada equipo (inscripción, arbitraje, manuales) y su saldo.',
        'filtros' => ['equipo', 'fechas', 'estado'],
    ],
    'jugadores' => [
        'nombre' => 'Jugadores por equipo',
        'icono' => 'bi-people',
        'descripcion' => 'La plantilla con goles, tarjetas y multas pendientes de cada jugador.',
        'filtros' => ['equipo', 'fechas'],
    ],
];

/** Opciones del filtro "estado" según el reporte. '' = todos. */
const REPORTES_ESTADOS = [
    'tarjetas' => ['' => 'Todas', 'pendiente' => 'Multa pendiente', 'pagada' => 'Multa pagada', 'condonada' => 'Multa condonada', 'sin_multa' => 'Sin multa'],
    'resultados' => ['' => 'Todos', 'jugado' => 'Jugados', 'programado' => 'Por jugar'],
    'pagos' => ['' => 'Todos', 'cargo' => 'Solo cargos', 'pago' => 'Solo pagos'],
];

/**
 * Deja los filtros del formulario limpios y con valores válidos.
 *
 * @return array{tipo:string, equipo_id:int, desde:string, hasta:string, estado:string, fecha_por:string}
 */
function reporte_filtros(array $entrada): array
{
    $tipo = (string) ($entrada['tipo'] ?? '');
    if (!isset(REPORTES_TIPOS[$tipo])) {
        $tipo = 'tarjetas';
    }
    $fecha = fn($v) => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $v) ? (string) $v : '';
    $desde = $fecha($entrada['desde'] ?? '');
    $hasta = $fecha($entrada['hasta'] ?? '');
    // Si las pusieron al revés se voltean, en vez de devolver un reporte vacío sin explicar.
    if ($desde !== '' && $hasta !== '' && $desde > $hasta) {
        [$desde, $hasta] = [$hasta, $desde];
    }
    $estado = (string) ($entrada['estado'] ?? '');
    if (!array_key_exists($estado, REPORTES_ESTADOS[$tipo] ?? [])) {
        $estado = '';
    }

    return [
        'tipo' => $tipo,
        'equipo_id' => max(0, (int) ($entrada['equipo_id'] ?? 0)),
        'desde' => $desde,
        'hasta' => $hasta,
        'estado' => $estado,
        'fecha_por' => ($entrada['fecha_por'] ?? '') === 'pago' ? 'pago' : 'partido',
    ];
}

/** ¿La fecha (Y-m-d) cae dentro del rango? Rango vacío = siempre sí; fecha vacía con rango = no. */
function reporte_en_rango(string $fecha, string $desde, string $hasta): bool
{
    if ($desde === '' && $hasta === '') {
        return true;
    }
    $fecha = substr($fecha, 0, 10);
    if ($fecha === '') {
        return false;
    }
    return ($desde === '' || $fecha >= $desde) && ($hasta === '' || $fecha <= $hasta);
}

/**
 * Fecha local (Y-m-d) de un TIMESTAMPTZ de la base. La hora de pago se guarda en UTC: un
 * pago de las 7 de la noche en Guatemala ya es "mañana" en UTC, y el reporte por fecha de
 * pago lo pondría en el día equivocado.
 */
function reporte_fecha_local(?string $marca): string
{
    if ($marca === null || $marca === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($marca))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d');
    } catch (Throwable $e) {
        return substr($marca, 0, 10);
    }
}

/** "Local vs Visitante" del partido, o '' si no existe. */
function reporte_encuentro(?array $partido, array $equiposPorId): string
{
    if ($partido === null) {
        return '';
    }
    return ($equiposPorId[(int) $partido['equipo_local']]['nombre'] ?? '?')
        . ' vs ' . ($equiposPorId[(int) $partido['equipo_visitante']]['nombre'] ?? '?');
}

/** Texto que explica los filtros aplicados, para el encabezado de la hoja impresa. */
function reporte_descripcion_filtros(array $f, array $equiposPorId): string
{
    $partes = [];
    $partes[] = $f['equipo_id'] > 0 ? 'Equipo: ' . ($equiposPorId[$f['equipo_id']]['nombre'] ?? '?') : 'Todos los equipos';
    if ($f['desde'] !== '' || $f['hasta'] !== '') {
        $base = $f['tipo'] === 'tarjetas' && $f['fecha_por'] === 'pago' ? 'Pagadas' : 'Fechas';
        $partes[] = $base . ': ' . ($f['desde'] !== '' ? 'del ' . reporte_fecha_texto($f['desde']) : 'desde el inicio')
            . ' ' . ($f['hasta'] !== '' ? 'al ' . reporte_fecha_texto($f['hasta']) : 'a hoy');
    }
    if ($f['estado'] !== '' && isset(REPORTES_ESTADOS[$f['tipo']][$f['estado']])) {
        $partes[] = REPORTES_ESTADOS[$f['tipo']][$f['estado']];
    }
    return implode(' · ', $partes);
}

/** 2026-12-13 → 13/12/2026 (vacío se queda vacío). */
function reporte_fecha_texto(string $fecha): string
{
    $d = DateTimeImmutable::createFromFormat('Y-m-d', substr($fecha, 0, 10));
    return $d ? $d->format('d/m/Y') : $fecha;
}

/**
 * Arma el reporte pedido.
 *
 * $datos: torneo, equipos (por id), partidos (por id), jugadores (por id), eventos,
 *         sanciones, movimientos.
 *
 * @return array{titulo:string, secciones: array<int, array{titulo:string, columnas:array, formatos:array, filas:array, pie:?array}>}
 */
function reporte_armar(array $f, array $datos): array
{
    return match ($f['tipo']) {
        'goles' => reporte_goles($f, $datos),
        'resultados' => reporte_resultados($f, $datos),
        'pagos' => reporte_pagos($f, $datos),
        'jugadores' => reporte_jugadores($f, $datos),
        default => reporte_tarjetas($f, $datos),
    };
}

// --- Tarjetas y multas ------------------------------------------------------

function reporte_tarjetas(array $f, array $d): array
{
    $torneo = $d['torneo'];
    $deporte = $torneo['deporte'] ?? null;
    $etTA = etiqueta_ta($deporte);
    $etTR = etiqueta_tr($deporte);

    // Hay columnas de dinero si la liga cobra multas o si alguna vez se cobraron.
    $conMultas = torneo_cobra_multas($torneo) || !empty($d['sanciones']);

    $sancionPorEvento = [];
    foreach ($d['sanciones'] as $s) {
        $sancionPorEvento[(int) $s['evento_id']] = $s;
    }

    $detalle = [];
    $porEquipo = [];
    foreach ($d['eventos'] as $ev) {
        $tipo = (string) ($ev['tipo'] ?? '');
        if ($tipo !== 'amarilla' && $tipo !== 'roja') {
            continue;
        }
        $equipoId = (int) $ev['equipo_id'];
        if ($f['equipo_id'] > 0 && $equipoId !== $f['equipo_id']) {
            continue;
        }
        $partido = $d['partidos'][(int) $ev['partido_id']] ?? null;
        $s = $sancionPorEvento[(int) $ev['id']] ?? null;
        $estado = $s !== null ? (string) $s['estado'] : 'sin_multa';
        $fechaPago = $s !== null && $s['estado'] !== 'pendiente' ? reporte_fecha_local($s['cobrada_en'] ?? null) : '';
        $fechaPartido = (string) ($partido['fecha'] ?? '');

        if ($f['estado'] !== '' && $estado !== $f['estado']) {
            continue;
        }
        $fechaFiltro = $f['fecha_por'] === 'pago' ? $fechaPago : $fechaPartido;
        if (!reporte_en_rango($fechaFiltro, $f['desde'], $f['hasta'])) {
            continue;
        }

        $jugador = $d['jugadores'][(int) ($ev['jugador_id'] ?? 0)] ?? null;
        $monto = $s !== null ? (float) $s['monto'] : 0.0;
        $fila = [
            $fechaPartido,
            (int) ($partido['jornada'] ?? 0),
            reporte_encuentro($partido, $d['equipos']),
            (string) ($d['equipos'][$equipoId]['nombre'] ?? ''),
            (string) ($jugador['dorsal'] ?? ''),
            (string) ($jugador['nombre'] ?? 'Jugador eliminado'),
            $tipo === 'amarilla' ? $etTA : $etTR,
        ];
        if ($conMultas) {
            $fila[] = $s !== null ? $monto : null;
            $fila[] = REPORTES_ESTADOS['tarjetas'][$estado] ?? $estado;
            $fila[] = $fechaPago;
            $fila[] = (string) ($s['nota'] ?? '');
        }
        $detalle[] = ['orden' => $fechaPartido . sprintf('%05d', (int) ($partido['jornada'] ?? 0)) . $fila[3] . $fila[5], 'fila' => $fila];

        $porEquipo[$equipoId] ??= ['ta' => 0, 'tr' => 0, 'total' => 0.0, 'pagada' => 0.0, 'pendiente' => 0.0, 'condonada' => 0.0];
        $porEquipo[$equipoId][$tipo === 'amarilla' ? 'ta' : 'tr']++;
        if ($s !== null) {
            $porEquipo[$equipoId]['total'] += $monto;
            $porEquipo[$equipoId][$estado] = ($porEquipo[$equipoId][$estado] ?? 0) + $monto;
        }
    }
    usort($detalle, fn($a, $b) => strcmp($a['orden'], $b['orden']));

    $columnas = ['Fecha', 'Jornada', 'Encuentro', 'Equipo', '#', 'Jugador', 'Tarjeta'];
    $formatos = ['fecha', 'entero', 'texto', 'texto', 'texto', 'texto', 'texto'];
    if ($conMultas) {
        array_push($columnas, 'Multa', 'Estado', 'Pagada el', 'Nota');
        array_push($formatos, 'dinero', 'texto', 'fecha', 'texto');
    }

    // Resumen por equipo, con totales al pie.
    $resumen = [];
    $tot = ['ta' => 0, 'tr' => 0, 'total' => 0.0, 'pagada' => 0.0, 'pendiente' => 0.0, 'condonada' => 0.0];
    foreach ($porEquipo as $equipoId => $r) {
        $fila = [(string) ($d['equipos'][$equipoId]['nombre'] ?? '?'), $r['ta'], $r['tr'], $r['ta'] + $r['tr']];
        if ($conMultas) {
            array_push($fila, $r['total'], $r['pagada'], $r['pendiente'], $r['condonada']);
        }
        $resumen[] = $fila;
        foreach ($tot as $k => $_) {
            $tot[$k] += $r[$k];
        }
    }
    usort($resumen, fn($a, $b) => $b[3] <=> $a[3] ?: strcmp($a[0], $b[0]));
    $colRes = ['Equipo', $etTA, $etTR, 'Total tarjetas'];
    $fmtRes = ['texto', 'entero', 'entero', 'entero'];
    $pie = ['TOTAL', $tot['ta'], $tot['tr'], $tot['ta'] + $tot['tr']];
    if ($conMultas) {
        array_push($colRes, 'Multas', 'Pagado', 'Pendiente', 'Condonado');
        array_push($fmtRes, 'dinero', 'dinero', 'dinero', 'dinero');
        array_push($pie, $tot['total'], $tot['pagada'], $tot['pendiente'], $tot['condonada']);
    }

    // Totales del detalle: cuántas tarjetas y, si se cobran, la suma de las multas. Con el
    // filtro "Multa pagada" esa suma es lo cobrado en el periodo.
    $filasDetalle = array_column($detalle, 'fila');
    $pieDetalle = null;
    if (!empty($filasDetalle)) {
        $pieDetalle = array_fill(0, count($columnas), null);
        $pieDetalle[0] = 'TOTAL';
        $pieDetalle[6] = count($filasDetalle) . (count($filasDetalle) === 1 ? ' tarjeta' : ' tarjetas');
        if ($conMultas) {
            $pieDetalle[7] = array_sum(array_map(fn($f) => (float) ($f[7] ?? 0), $filasDetalle));
        }
    }

    return [
        'titulo' => 'Reporte de tarjetas y multas',
        'secciones' => [
            ['titulo' => 'Detalle de tarjetas', 'columnas' => $columnas, 'formatos' => $formatos, 'filas' => $filasDetalle, 'pie' => $pieDetalle],
            ['titulo' => 'Resumen por equipo', 'columnas' => $colRes, 'formatos' => $fmtRes, 'filas' => $resumen, 'pie' => $resumen ? $pie : null],
        ],
    ];
}

// --- Goles ----------------------------------------------------------------

function reporte_goles(array $f, array $d): array
{
    $deporte = $d['torneo']['deporte'] ?? null;
    $basket = es_basketball($deporte);
    $esFutbol = deporte_clave($deporte) === 'futbol';
    $etiquetas = $basket ? TIPOS_PUNTO_LABEL : (es_voleibol($deporte) ? TIPOS_PUNTO_VOLEIBOL_LABEL : TIPOS_GOL_LABEL);
    $etAnot = etiqueta_anotaciones($deporte);

    $detalle = [];
    $eventosFiltrados = [];
    $porEquipo = [];
    foreach ($d['eventos'] as $ev) {
        if (($ev['tipo'] ?? '') !== 'gol') {
            continue;
        }
        $equipoId = (int) $ev['equipo_id'];
        if ($f['equipo_id'] > 0 && $equipoId !== $f['equipo_id']) {
            continue;
        }
        $partido = $d['partidos'][(int) $ev['partido_id']] ?? null;
        $fecha = (string) ($partido['fecha'] ?? '');
        if (!reporte_en_rango($fecha, $f['desde'], $f['hasta'])) {
            continue;
        }
        $eventosFiltrados[] = $ev;
        $esAutogol = $esFutbol && ($ev['tipo_gol'] ?? '') === 'autogol';
        $valor = $basket ? (TIPOS_PUNTO_VALOR[$ev['tipo_gol'] ?? ''] ?? 1) : 1;
        $jugador = $d['jugadores'][(int) ($ev['jugador_id'] ?? 0)] ?? null;

        $fila = [
            $fecha,
            (int) ($partido['jornada'] ?? 0),
            reporte_encuentro($partido, $d['equipos']),
            (string) ($d['equipos'][$equipoId]['nombre'] ?? ''),
            (string) ($jugador['dorsal'] ?? ''),
            (string) ($jugador['nombre'] ?? 'Sin jugador'),
            isset($ev['minuto']) && $ev['minuto'] !== null && $ev['minuto'] !== '' ? (int) $ev['minuto'] : null,
            $esAutogol ? 'Autogol (cuenta para el rival)' : (string) ($etiquetas[$ev['tipo_gol'] ?? ''] ?? ''),
        ];
        if ($basket) {
            $fila[] = $valor;
        }
        $detalle[] = ['orden' => $fecha . sprintf('%05d%05d', (int) ($partido['jornada'] ?? 0), (int) ($ev['minuto'] ?? 0)), 'fila' => $fila];

        if (!$esAutogol) {
            $porEquipo[$equipoId] = ($porEquipo[$equipoId] ?? 0) + $valor;
        }
    }
    usort($detalle, fn($a, $b) => strcmp($a['orden'], $b['orden']));

    $columnas = ['Fecha', 'Jornada', 'Encuentro', 'Equipo', '#', 'Jugador', 'Min.', 'Tipo'];
    $formatos = ['fecha', 'entero', 'texto', 'texto', 'texto', 'texto', 'entero', 'texto'];
    if ($basket) {
        $columnas[] = 'Puntos';
        $formatos[] = 'entero';
    }

    // Goleadores: misma cuenta que la tabla pública, pero solo con lo filtrado.
    $goleadores = [];
    $pos = 0;
    foreach (calcular_goleadores($eventosFiltrados, array_values($d['jugadores']), $d['equipos'], $deporte) as $g) {
        $goleadores[] = [++$pos, (string) ($g['jugador']['nombre'] ?? ''), (string) ($g['equipo']['nombre'] ?? ''), (int) $g['goles']];
    }

    $resumen = [];
    foreach ($porEquipo as $equipoId => $total) {
        $resumen[] = [(string) ($d['equipos'][$equipoId]['nombre'] ?? '?'), $total];
    }
    usort($resumen, fn($a, $b) => $b[1] <=> $a[1] ?: strcmp($a[0], $b[0]));

    return [
        'titulo' => 'Reporte de ' . mb_strtolower($etAnot),
        'secciones' => [
            ['titulo' => 'Tabla de ' . mb_strtolower($basket ? 'anotadores' : 'goleadores'), 'columnas' => ['Pos.', 'Jugador', 'Equipo', $etAnot], 'formatos' => ['entero', 'texto', 'texto', 'entero'], 'filas' => $goleadores, 'pie' => null],
            ['titulo' => 'Por equipo', 'columnas' => ['Equipo', $etAnot], 'formatos' => ['texto', 'entero'], 'filas' => $resumen, 'pie' => $resumen ? ['TOTAL', array_sum(array_column($resumen, 1))] : null],
            ['titulo' => 'Detalle', 'columnas' => $columnas, 'formatos' => $formatos, 'filas' => array_column($detalle, 'fila'), 'pie' => null],
        ],
    ];
}

// --- Resultados -----------------------------------------------------------

function reporte_resultados(array $f, array $d): array
{
    $filas = [];
    $partidos = array_values($d['partidos']);
    usort($partidos, fn($a, $b) => strcmp((string) $a['fecha'] . $a['hora'], (string) $b['fecha'] . $b['hora']));
    $jugados = 0;
    foreach ($partidos as $p) {
        $local = (int) $p['equipo_local'];
        $visita = (int) $p['equipo_visitante'];
        if ($f['equipo_id'] > 0 && $local !== $f['equipo_id'] && $visita !== $f['equipo_id']) {
            continue;
        }
        if (!reporte_en_rango((string) $p['fecha'], $f['desde'], $f['hasta'])) {
            continue;
        }
        $jugado = ($p['estado'] ?? '') === 'jugado';
        if (($f['estado'] === 'jugado' && !$jugado) || ($f['estado'] === 'programado' && $jugado)) {
            continue;
        }
        $jugados += $jugado ? 1 : 0;
        $filas[] = [
            (int) ($p['jornada'] ?? 0),
            (string) (FASES_LABEL[$p['fase'] ?? 'grupos'] ?? ''),
            (string) $p['fecha'],
            (string) ($p['hora'] ?? ''),
            (string) ($p['cancha'] ?? ''),
            (string) ($d['equipos'][$local]['nombre'] ?? '?'),
            $jugado ? (int) $p['marcador_local'] . ' - ' . (int) $p['marcador_visitante'] : 'vs',
            (string) ($d['equipos'][$visita]['nombre'] ?? '?'),
            $jugado ? (!empty($p['por_default']) ? 'Jugado (W.O.)' : 'Jugado') : 'Por jugar',
        ];
    }

    return [
        'titulo' => 'Reporte de resultados',
        'secciones' => [[
            'titulo' => count($filas) . ' encuentros · ' . $jugados . ' jugados',
            'columnas' => ['Jornada', 'Fase', 'Fecha', 'Hora', 'Cancha', 'Local', 'Resultado', 'Visitante', 'Estado'],
            'formatos' => ['entero', 'texto', 'fecha', 'texto', 'texto', 'texto', 'texto', 'texto', 'texto'],
            'filas' => $filas,
            'pie' => null,
        ]],
    ];
}

// --- Pagos y cuentas ------------------------------------------------------

function reporte_pagos(array $f, array $d): array
{
    $filas = [];
    $porEquipo = [];
    $movs = $d['movimientos'];
    usort($movs, fn($a, $b) => strcmp((string) $a['fecha'], (string) $b['fecha']) ?: ((int) $a['id'] <=> (int) $b['id']));
    foreach ($movs as $m) {
        $equipoId = (int) $m['equipo_id'];
        if ($f['equipo_id'] > 0 && $equipoId !== $f['equipo_id']) {
            continue;
        }
        if (!reporte_en_rango((string) $m['fecha'], $f['desde'], $f['hasta'])) {
            continue;
        }
        $esPago = ($m['tipo'] ?? '') === 'pago';
        if (($f['estado'] === 'pago' && !$esPago) || ($f['estado'] === 'cargo' && $esPago)) {
            continue;
        }
        $monto = (float) $m['monto'];
        $filas[] = [
            (string) $m['fecha'],
            (string) ($d['equipos'][$equipoId]['nombre'] ?? '?'),
            movimiento_origen_nombre((string) ($m['origen'] ?? '')),
            (string) ($m['concepto'] ?? ''),
            $esPago ? null : $monto,
            $esPago ? $monto : null,
            (string) ($m['nota'] ?? ''),
        ];
        $porEquipo[$equipoId] ??= ['cargos' => 0.0, 'pagos' => 0.0];
        $porEquipo[$equipoId][$esPago ? 'pagos' : 'cargos'] += $monto;
    }

    $resumen = [];
    $tc = 0.0;
    $tp = 0.0;
    foreach ($porEquipo as $equipoId => $r) {
        $resumen[] = [(string) ($d['equipos'][$equipoId]['nombre'] ?? '?'), $r['cargos'], $r['pagos'], $r['cargos'] - $r['pagos']];
        $tc += $r['cargos'];
        $tp += $r['pagos'];
    }
    usort($resumen, fn($a, $b) => $b[3] <=> $a[3] ?: strcmp($a[0], $b[0]));

    return [
        'titulo' => 'Reporte de pagos y cuentas',
        'secciones' => [
            ['titulo' => 'Saldo por equipo', 'columnas' => ['Equipo', 'Cargos', 'Pagos', 'Saldo pendiente'], 'formatos' => ['texto', 'dinero', 'dinero', 'dinero'], 'filas' => $resumen, 'pie' => $resumen ? ['TOTAL', $tc, $tp, $tc - $tp] : null],
            ['titulo' => 'Movimientos', 'columnas' => ['Fecha', 'Equipo', 'Movimiento', 'Concepto', 'Cargo', 'Pago', 'Nota'], 'formatos' => ['fecha', 'texto', 'texto', 'texto', 'dinero', 'dinero', 'texto'], 'filas' => $filas, 'pie' => $filas ? ['TOTAL', null, null, null, $tc, $tp, null] : null],
        ],
    ];
}

// --- Jugadores ------------------------------------------------------------

function reporte_jugadores(array $f, array $d): array
{
    $deporte = $d['torneo']['deporte'] ?? null;
    $esFutbol = deporte_clave($deporte) === 'futbol';
    $basket = es_basketball($deporte);

    // Estadística de cada jugador dentro del rango de fechas pedido.
    $stats = [];
    foreach ($d['eventos'] as $ev) {
        $partido = $d['partidos'][(int) $ev['partido_id']] ?? null;
        if (!reporte_en_rango((string) ($partido['fecha'] ?? ''), $f['desde'], $f['hasta'])) {
            continue;
        }
        $jid = (int) ($ev['jugador_id'] ?? 0);
        $stats[$jid] ??= ['goles' => 0, 'ta' => 0, 'tr' => 0];
        $tipo = (string) ($ev['tipo'] ?? '');
        if ($tipo === 'gol' && !($esFutbol && ($ev['tipo_gol'] ?? '') === 'autogol')) {
            $stats[$jid]['goles'] += $basket ? (TIPOS_PUNTO_VALOR[$ev['tipo_gol'] ?? ''] ?? 1) : 1;
        } elseif ($tipo === 'amarilla') {
            $stats[$jid]['ta']++;
        } elseif ($tipo === 'roja') {
            $stats[$jid]['tr']++;
        }
    }
    $deuda = [];
    foreach ($d['sanciones'] as $s) {
        if (($s['estado'] ?? '') === 'pendiente') {
            $deuda[(int) $s['jugador_id']] = ($deuda[(int) $s['jugador_id']] ?? 0) + (float) $s['monto'];
        }
    }
    $conMultas = torneo_cobra_multas($d['torneo']) || !empty($d['sanciones']);

    $jugadores = array_values(array_filter($d['jugadores'], fn($j) => $f['equipo_id'] === 0 || (int) $j['equipo_id'] === $f['equipo_id']));
    usort($jugadores, function ($a, $b) use ($d) {
        $ea = (string) ($d['equipos'][(int) $a['equipo_id']]['nombre'] ?? '');
        $eb = (string) ($d['equipos'][(int) $b['equipo_id']]['nombre'] ?? '');
        return strcmp($ea, $eb) ?: ((int) $a['dorsal'] <=> (int) $b['dorsal']);
    });

    $filas = [];
    foreach ($jugadores as $j) {
        $jid = (int) $j['id'];
        $st = $stats[$jid] ?? ['goles' => 0, 'ta' => 0, 'tr' => 0];
        $estado = !jugador_inscrito($j) ? 'Inscripción pendiente' : (!empty($j['activo']) ? 'Activo' : 'Inactivo');
        $fila = [
            (string) ($d['equipos'][(int) $j['equipo_id']]['nombre'] ?? '?'),
            (string) $j['dorsal'],
            (string) $j['nombre'],
            $estado,
            $st['goles'], $st['ta'], $st['tr'],
        ];
        if ($conMultas) {
            $fila[] = $deuda[$jid] ?? 0.0;
        }
        $filas[] = $fila;
    }

    $columnas = ['Equipo', '#', 'Jugador', 'Estado', etiqueta_anotaciones($deporte), etiqueta_ta($deporte), etiqueta_tr($deporte)];
    $formatos = ['texto', 'texto', 'texto', 'texto', 'entero', 'entero', 'entero'];
    if ($conMultas) {
        $columnas[] = 'Multas pendientes';
        $formatos[] = 'dinero';
    }

    return [
        'titulo' => 'Reporte de jugadores',
        'secciones' => [[
            'titulo' => count($filas) . ' jugadores',
            'columnas' => $columnas,
            'formatos' => $formatos,
            'filas' => $filas,
            // Totales de la plantilla: goles, tarjetas y lo que se debe en multas.
            'pie' => $filas ? reporte_pie_sumas($filas, $formatos, [4, 5, 6, 7]) : null,
        ]],
    ];
}

/** Fila de totales: 'TOTAL' en la primera columna y la suma de las columnas pedidas. */
function reporte_pie_sumas(array $filas, array $formatos, array $columnasSumar): array
{
    $pie = array_fill(0, count($formatos), null);
    $pie[0] = 'TOTAL';
    foreach ($columnasSumar as $k) {
        if (!isset($formatos[$k])) {
            continue;
        }
        $suma = array_sum(array_map(fn($f) => $f[$k] ?? 0, $filas));
        $pie[$k] = $formatos[$k] === 'dinero' ? (float) $suma : (int) $suma;
    }
    return $pie;
}

// --- Salidas ---------------------------------------------------------------

/** Valor de una celda para pantalla e impresión (el Excel recibe el valor crudo). */
function reporte_celda_texto(mixed $valor, string $formato, array $torneo): string
{
    if ($valor === null || $valor === '') {
        return '';
    }
    return match ($formato) {
        'fecha' => reporte_fecha_texto((string) $valor),
        'dinero' => sancion_monto_texto($torneo, (float) $valor),
        default => (string) $valor,
    };
}

/**
 * Hojas para excel_generar(): una por sección, con el encabezado en la primera fila y el
 * pie (totales) al final. Los nombres de hoja se recortan a 31 caracteres (límite de Excel).
 */
function reporte_hojas_excel(array $reporte, string $moneda): array
{
    $hojas = [];
    foreach ($reporte['secciones'] as $i => $sec) {
        $encabezado = [];
        foreach ($sec['columnas'] as $k => $col) {
            $encabezado[] = ($sec['formatos'][$k] ?? '') === 'dinero' ? $col . ' (' . $moneda . ')' : $col;
        }
        $formatos = $sec['formatos'];
        // Fechas reales (se ven dd/mm/aaaa y se pueden ordenar y filtrar en Excel) y montos
        // con dos decimales. El resto va tal cual.
        $filas = [$encabezado];
        foreach ($sec['filas'] as $fila) {
            $filas[] = array_map(fn($v, $k) => match ($formatos[$k] ?? 'texto') {
                'fecha' => excel_celda($v, 'fecha'),
                'dinero' => excel_celda($v, 'dinero'),
                default => $v,
            }, $fila, array_keys($fila));
        }
        if (!empty($sec['pie'])) {
            $filas[] = array_map(fn($v, $k) => ($formatos[$k] ?? '') === 'dinero'
                ? excel_celda($v, 'dinero_negrita')
                : excel_celda($v, 'negrita'), $sec['pie'], array_keys($sec['pie']));
        }
        $nombre = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', '', $sec['titulo']) ?: 'Hoja ' . ($i + 1), 0, 31);
        $hojas[$nombre] = $filas;
    }
    return $hojas;
}
