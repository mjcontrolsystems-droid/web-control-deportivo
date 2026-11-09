<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Panel de usuarios del super-admin.
//
// Junta en una sola fila por correo las dos fuentes que antes estaban separadas: la lista
// blanca (quién puede entrar y con cuántas copas) y las cuentas (quién ya entró, cuándo,
// si está suspendido y qué copas tiene). Un correo autorizado que todavía no entra
// aparece como "pendiente"; una cuenta que no está en la lista (colaboradores invitados,
// cuentas anteriores al cierre del registro) aparece igual, con su rol.
//
// Función pura: recibe los datos ya leídos. Se prueba en scripts/tests.php.
// ---------------------------------------------------------------------------

const USUARIO_ROL_SUPERADMIN = 'superadmin';
const USUARIO_ROL_ORGANIZADOR = 'organizador';
const USUARIO_ROL_COLABORADOR = 'colaborador';

const USUARIO_ROLES_LABEL = [
    USUARIO_ROL_SUPERADMIN => 'Super-admin',
    USUARIO_ROL_ORGANIZADOR => 'Organizador',
    USUARIO_ROL_COLABORADOR => 'Colaborador',
];

const USUARIO_ESTADO_ACTIVO = 'activo';
const USUARIO_ESTADO_SUSPENDIDO = 'suspendido';
const USUARIO_ESTADO_PENDIENTE = 'pendiente';

const USUARIO_ESTADOS_LABEL = [
    USUARIO_ESTADO_ACTIVO => 'Activo',
    USUARIO_ESTADO_SUSPENDIDO => 'Suspendido',
    USUARIO_ESTADO_PENDIENTE => 'Aún no entra',
];

/**
 * @param array $correos        filas de correos_autorizados
 * @param array $usuarios       filas de usuarios
 * @param array $copasPorUsuario usuario_id => lista de copas propias
 * @param array $consumidas     usuario_id => copas creadas en total (usuario_torneos_consumidos)
 * @param array $correosSuper   correos super-admin (en minúsculas)
 *
 * @return array<int, array> una fila por correo, ordenadas por nombre
 */
function usuarios_panel_filas(array $correos, array $usuarios, array $copasPorUsuario, array $consumidas, array $correosSuper): array
{
    $filas = [];

    foreach ($correos as $c) {
        $email = mb_strtolower(trim((string) $c['email']));
        $filas[$email] = [
            'email' => $email,
            'correo_id' => (int) $c['id'],
            'limite' => (int) ($c['limite_torneos'] ?? LIMITE_TORNEOS_POR_DEFECTO),
            'autorizado_en' => (string) ($c['creado_en'] ?? ''),
            'usuario' => null,
        ];
    }
    foreach ($usuarios as $u) {
        $email = mb_strtolower(trim((string) $u['email']));
        $filas[$email] ??= [
            'email' => $email,
            'correo_id' => null,
            'limite' => null,
            'autorizado_en' => '',
            'usuario' => null,
        ];
        $filas[$email]['usuario'] = $u;
    }

    $resultado = [];
    foreach ($filas as $email => $f) {
        $u = $f['usuario'];
        $uid = $u !== null ? (int) $u['id'] : 0;
        $copas = $uid > 0 ? ($copasPorUsuario[$uid] ?? []) : [];
        $esSuper = in_array($email, $correosSuper, true);

        if ($esSuper) {
            $rol = USUARIO_ROL_SUPERADMIN;
        } elseif ($f['correo_id'] !== null || !empty($copas)) {
            $rol = USUARIO_ROL_ORGANIZADOR;
        } else {
            $rol = USUARIO_ROL_COLABORADOR;
        }

        $estado = match (true) {
            $u === null => USUARIO_ESTADO_PENDIENTE,
            !empty($u['suspendido_en']) && !$esSuper => USUARIO_ESTADO_SUSPENDIDO,
            default => USUARIO_ESTADO_ACTIVO,
        };

        $resultado[] = [
            'email' => $email,
            'nombre' => $u !== null ? trim((string) ($u['nombre'] ?? '')) : '',
            'usuario_id' => $uid ?: null,
            'correo_id' => $f['correo_id'],
            'rol' => $rol,
            'estado' => $estado,
            // Cupo: null = sin límite (super-admin). Fuera de la lista blanca rige el de
            // por defecto, igual que en usuario_limite_torneos().
            'limite' => $esSuper ? null : ($f['limite'] ?? LIMITE_TORNEOS_POR_DEFECTO),
            'usadas' => $uid > 0 ? max((int) ($consumidas[$uid] ?? 0), count($copas)) : 0,
            'copas' => $copas,
            'ultimo_acceso' => $u !== null ? (string) ($u['ultimo_acceso'] ?? '') : '',
            'alta' => $u !== null ? (string) ($u['creado_en'] ?? '') : $f['autorizado_en'],
        ];
    }

    usort($resultado, function (array $a, array $b): int {
        // Super-admin primero; luego por nombre (o correo si aún no tiene nombre).
        $pa = $a['rol'] === USUARIO_ROL_SUPERADMIN ? 0 : 1;
        $pb = $b['rol'] === USUARIO_ROL_SUPERADMIN ? 0 : 1;
        return $pa <=> $pb ?: strcasecmp($a['nombre'] ?: $a['email'], $b['nombre'] ?: $b['email']);
    });

    return $resultado;
}

/**
 * Cifras de las tarjetas de arriba del panel.
 *
 * @return array{total:int, activos:int, suspendidos:int, pendientes:int, copas:int}
 */
function usuarios_panel_resumen(array $filas): array
{
    $r = ['total' => count($filas), 'activos' => 0, 'suspendidos' => 0, 'pendientes' => 0, 'copas' => 0];
    foreach ($filas as $f) {
        match ($f['estado']) {
            USUARIO_ESTADO_ACTIVO => $r['activos']++,
            USUARIO_ESTADO_SUSPENDIDO => $r['suspendidos']++,
            default => $r['pendientes']++,
        };
        $r['copas'] += count($f['copas']);
    }
    return $r;
}
