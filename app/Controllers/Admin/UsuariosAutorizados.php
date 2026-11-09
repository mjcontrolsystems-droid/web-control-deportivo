<?php
declare(strict_types=1);

/**
 * Panel de usuarios del super-admin.
 *
 * Una sola pantalla para administrar a los clientes de la plataforma: autorizar un correo
 * nuevo, darle cupo de copas, suspender o reactivar su cuenta, y ver sus copas con el
 * código, la licencia y el enlace de cada una (regenerar el código o mover la licencia
 * sin tener que entrar a la copa).
 *
 * El registro público está cerrado: solo los correos de esta lista pueden crear una
 * cuenta con "Continuar con Google".
 */

auth_requerir();
$usuarioSesion = usuarios_obtener_por_id((int) $_SESSION['usuario_id']);
if (!es_superadmin($usuarioSesion)) {
    http_response_code(403);
    exit('No tienes permiso para ver esta página.');
}

$volver = url('admin/usuarios_autorizados.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validar();
    $accion = (string) ($_POST['accion'] ?? '');

    // --- Autorizar un correo nuevo ---
    if ($accion === 'agregar') {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirigir_con_mensaje($volver, 'error', 'Ingresa un correo válido.');
        }
        if (correos_autorizados_obtener_por_email($email) !== null) {
            redirigir_con_mensaje($volver, 'error', "{$email} ya está autorizado. Cámbiale el cupo desde la tabla.");
        }
        $limite = max(0, min(999, (int) ($_POST['limite_torneos'] ?? LIMITE_TORNEOS_POR_DEFECTO)));
        correos_autorizados_agregar($email, $limite);
        $avisado = correo_avisar_autorizado($email, $limite);
        bitacora_registrar('correo_autorizado', "{$email} autorizado con cupo de {$limite}");
        $sufijo = $avisado ? ' Se le envió un correo de aviso.' : (correo_configurado() ? ' (No se pudo enviar el correo de aviso.)' : '');
        redirigir_con_mensaje($volver, 'success', "{$email} autorizado con {$limite} copa(s) o liga(s).{$sufijo}");
    }

    // --- Cambiar el cupo (por correo: también sirve para cuentas que no estaban en la lista) ---
    if ($accion === 'actualizar_limite') {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $limite = max(0, min(999, (int) ($_POST['limite_torneos'] ?? 0)));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            redirigir_con_mensaje($volver, 'error', 'Correo no válido.');
        }
        $fila = correos_autorizados_obtener_por_email($email);
        if ($fila !== null) {
            correos_autorizados_actualizar_limite((int) $fila['id'], $limite);
        } else {
            correos_autorizados_agregar($email, $limite);
        }
        $avisado = $limite > 0 && usuarios_obtener_por_email($email) !== null && correo_avisar_cupo($email, $limite);
        bitacora_registrar('cupo_actualizado', "{$email} ahora con cupo de {$limite}");
        redirigir_con_mensaje($volver, 'success', "Cupo de {$email}: {$limite} copa(s)." . ($avisado ? ' Se le envió un correo de aviso.' : ''));
    }

    // --- Quitar de la lista blanca (la cuenta y sus copas no se borran) ---
    if ($accion === 'eliminar') {
        $email = mb_strtolower(trim((string) ($_POST['email'] ?? '')));
        $fila = correos_autorizados_obtener_por_email($email);
        if ($fila !== null) {
            correos_autorizados_eliminar((int) $fila['id']);
            bitacora_registrar('correo_desautorizado', $email);
        }
        redirigir_con_mensaje($volver, 'success', "{$email} ya no está en la lista de autorizados.");
    }

    // --- Suspender o reactivar una cuenta ---
    if ($accion === 'suspender' || $accion === 'reactivar') {
        $cuenta = usuarios_obtener_por_id((int) ($_POST['usuario_id'] ?? 0));
        if ($cuenta === null) {
            redirigir_con_mensaje($volver, 'error', 'Cuenta no encontrada.');
        }
        if (es_superadmin($cuenta)) {
            redirigir_con_mensaje($volver, 'error', 'Una cuenta super-admin no se puede suspender.');
        }
        $suspender = $accion === 'suspender';
        usuario_suspender((int) $cuenta['id'], $suspender);
        bitacora_registrar($suspender ? 'usuario_suspendido' : 'usuario_reactivado', (string) $cuenta['email']);
        redirigir_con_mensaje(
            $volver,
            'success',
            $suspender
                ? "{$cuenta['email']} quedó suspendido: ya no puede entrar al panel. Sus copas siguen visibles al público."
                : "{$cuenta['email']} reactivado: ya puede volver a entrar."
        );
    }

    // --- Copas de un usuario: regenerar el código o mover la licencia ---
    if ($accion === 'regenerar_codigo' || $accion === 'fijar_licencia') {
        $copa = torneos_obtener_por_id((int) ($_POST['torneo_id'] ?? 0));
        if ($copa === null) {
            redirigir_con_mensaje($volver, 'error', 'Copa o liga no encontrada.');
        }
        if ($accion === 'regenerar_codigo') {
            $nuevo = torneos_regenerar_codigo((int) $copa['id'], (int) $copa['usuario_id']);
            bitacora_registrar('codigo_regenerado', '"' . $copa['nombre'] . '": código nuevo ' . $nuevo, (int) $copa['id']);
            redirigir_con_mensaje($volver, 'success', 'Código nuevo de "' . $copa['nombre'] . '": ' . $nuevo . '. El anterior ya no funciona.');
        }
        $fecha = trim((string) ($_POST['licencia_hasta'] ?? ''));
        if ($fecha !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
            redirigir_con_mensaje($volver, 'error', 'Fecha de licencia no válida.');
        }
        torneos_fijar_licencia((int) $copa['id'], $fecha !== '' ? $fecha : null);
        bitacora_registrar('licencia_actualizada', '"' . $copa['nombre'] . '": ' . ($fecha !== '' ? 'hasta el ' . licencia_fecha_legible($fecha) : 'sin límite'), (int) $copa['id']);
        redirigir_con_mensaje($volver, 'success', 'Licencia de "' . $copa['nombre'] . '" actualizada.');
    }

    redirigir_con_mensaje($volver, 'error', 'Acción no reconocida.');
}

// --- Datos del panel ---
$usuarios = usuarios_listar();
$copasPorUsuario = [];
foreach (torneos_listar(false) as $copa) {
    $copasPorUsuario[(int) $copa['usuario_id']][] = $copa;
}
$consumidas = [];
foreach ($usuarios as $u) {
    $consumidas[(int) $u['id']] = usuario_torneos_consumidos((int) $u['id']);
}

$filas = usuarios_panel_filas(correos_autorizados_listar(), $usuarios, $copasPorUsuario, $consumidas, SUPERADMIN_EMAILS);
$resumen = usuarios_panel_resumen($filas);

$seccion_activa = 'usuarios_autorizados';
$titulo_pagina = 'Usuarios';

vista_admin('admin/usuarios_autorizados', compact(
    'filas',
    'resumen',
    'seccion_activa',
    'titulo_pagina'
));
