<?php
declare(strict_types=1);

/**
 * Reportes del organizador: tarjetas y multas (con la fecha en que se pagaron), goles,
 * resultados, pagos de cada equipo y jugadores. Cada uno con filtros por equipo, fechas y
 * estado, y se puede ver en pantalla, imprimir (o guardar como PDF) y descargar en Excel.
 *
 * Los reportes se arman en app/Support/reportes.php; aquí solo se leen los datos de la
 * copa y se decide cómo entregarlo.
 */

auth_requerir();
$torneo = admin_requerir_torneo_activo();
// Mismo nivel que Cuentas y Exportar: los reportes llevan montos y deudas de cada equipo.
requerir_permiso('cuentas');

$equipos = equipos_listar($torneo['id']);
$equiposPorId = [];
foreach ($equipos as $eq) {
    $equiposPorId[(int) $eq['id']] = $eq;
}
$partidosPorId = [];
foreach (partidos_listar($torneo['id']) as $p) {
    $partidosPorId[(int) $p['id']] = $p;
}

$filtros = reporte_filtros($_GET);
// Un equipo que no es de esta copa (id manipulado en la URL) se trata como "todos".
if ($filtros['equipo_id'] > 0 && !isset($equiposPorId[$filtros['equipo_id']])) {
    $filtros['equipo_id'] = 0;
}

// Solo se lee de la base lo que el reporte pedido usa.
$tipo = $filtros['tipo'];
$datos = [
    'torneo' => $torneo,
    'equipos' => $equiposPorId,
    'partidos' => $partidosPorId,
    'jugadores' => in_array($tipo, ['tarjetas', 'goles', 'jugadores'], true) ? jugadores_por_id(jugadores_listar($torneo['id'])) : [],
    'eventos' => in_array($tipo, ['tarjetas', 'goles', 'jugadores'], true) ? eventos_de_torneo($torneo['id']) : [],
    'sanciones' => in_array($tipo, ['tarjetas', 'jugadores'], true) ? sanciones_listar($torneo['id']) : [],
    'movimientos' => $tipo === 'pagos' ? movimientos_listar($torneo['id']) : [],
];

$reporte = reporte_armar($filtros, $datos);
$descripcionFiltros = reporte_descripcion_filtros($filtros, $equiposPorId);

// --- Excel ---
if (($_GET['formato'] ?? '') === 'excel') {
    try {
        $contenido = excel_generar(reporte_hojas_excel($reporte, torneo_moneda($torneo)));
    } catch (RuntimeException $e) {
        redirigir_con_mensaje(url('admin/reportes.php'), 'error', $e->getMessage());
    }
    bitacora_registrar('exportacion_excel', $reporte['titulo'] . ' (' . $descripcionFiltros . ')', $torneo['id']);
    excel_descargar($contenido, ($torneo['slug'] ?? 'copa') . '_' . $tipo . '_' . date('Y-m-d') . '.xlsx');
}

// Enlace de descarga con los mismos filtros que se están viendo.
$urlExcel = url('admin/reportes.php?' . http_build_query(array_merge($filtros, ['formato' => 'excel'])));

$seccion_activa = 'reportes';
$titulo_pagina = 'Reportes';

vista_admin('admin/reportes', compact(
    'descripcionFiltros',
    'equipos',
    'filtros',
    'reporte',
    'seccion_activa',
    'titulo_pagina',
    'torneo',
    'urlExcel'
));
