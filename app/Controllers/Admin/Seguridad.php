<?php
declare(strict_types=1);

/**
 * Panel de Seguridad (solo super-admin): quién intentó entrar, desde qué IP, qué IPs
 * están frenadas ahora mismo y qué robots andan tanteando el sitio. Desde aquí también
 * se bloquea o desbloquea una IP a mano.
 */

auth_requerir();
$usuarioSesion = usuarios_obtener_por_id((int) $_SESSION['usuario_id']);
if (!es_superadmin($usuarioSesion)) {
    seguridad_registrar('acceso_denegado', 'Intentó abrir el panel de seguridad', (string) ($usuarioSesion['email'] ?? ''), (int) $_SESSION['usuario_id']);
    http_response_code(403);
    exit('No tienes permiso para ver esta página.');
}

$miIp = obtener_ip_cliente();
$volver = url('admin/seguridad.php?' . http_build_query(array_intersect_key($_GET, array_flip(['periodo', 'nivel', 'tipo', 'ip']))));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $accion = (string) ($_POST['accion'] ?? '');
    $ip = trim((string) ($_POST['ip'] ?? ''));
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        redirigir_con_mensaje($volver, 'error', 'Esa IP no es válida.');
    }

    if ($accion === 'bloquear_ip') {
        // Bloquear la propia IP dejaría al super-admin fuera de su propio sitio.
        if ($ip === $miIp) {
            redirigir_con_mensaje($volver, 'error', 'Esa es tu propia IP: si la bloqueas te quedas fuera del sitio.');
        }
        $motivo = trim((string) ($_POST['motivo'] ?? '')) ?: 'Bloqueada desde el panel de seguridad';
        seguridad_bloquear_ip($ip, $motivo, (int) $_SESSION['usuario_id']);
        bitacora_registrar('ip_bloqueada', "{$ip}: {$motivo}");
        redirigir_con_mensaje($volver, 'success', "IP {$ip} bloqueada: ya no puede abrir ninguna página del sitio.");
    }
    if ($accion === 'desbloquear_ip') {
        seguridad_desbloquear_ip($ip);
        bitacora_registrar('ip_desbloqueada', $ip);
        redirigir_con_mensaje($volver, 'success', "IP {$ip} desbloqueada.");
    }
    redirigir_con_mensaje($volver, 'error', 'Acción no reconocida.');
}

$filtros = seguridad_filtros($_GET);
$eventos = seguridad_eventos($filtros);
$resumen = seguridad_resumen($eventos);
$ipsBloqueadas = seguridad_ips_bloqueadas();
$ipsBloqueadasSet = array_flip(array_column($ipsBloqueadas, 'ip'));
$ipsFrenadas = seguridad_ips_frenadas_login();
$webhookActivo = (getenv('ERROR_WEBHOOK_URL') ?: '') !== '';

$seccion_activa = 'seguridad';
$titulo_pagina = 'Seguridad';

vista_admin('admin/seguridad', compact(
    'eventos',
    'filtros',
    'ipsBloqueadas',
    'ipsBloqueadasSet',
    'ipsFrenadas',
    'miIp',
    'resumen',
    'seccion_activa',
    'titulo_pagina',
    'webhookActivo'
));
