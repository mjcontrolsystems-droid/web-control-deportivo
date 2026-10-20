<?php
declare(strict_types=1);

require_once __DIR__ . '/../Support/bd.php';

// Plantilla de jugadores de cada equipo.
// Modelo: solo acceso a datos. Envuelve la capa genérica (db_leer/db_guardar) para que
// los controladores hablen de "jugadores" y no de nombres de tabla sueltos.

function jugadores_listar(int $torneoId): array
{
    return db_leer('jugadores', $torneoId);
}

function jugadores_guardar_todos(array $jugadores, int $torneoId): bool
{
    return db_guardar('jugadores', $jugadores, $torneoId);
}

function jugador_buscar(array $jugadores, int $id): ?array
{
    return db_buscar_por_id($jugadores, $id);
}

function jugador_nuevo_id(): int
{
    return db_siguiente_id_global('jugadores');
}

/**
 * Plantilla de un equipo concreto, dentro de la lista completa de la copa.
 */
function jugadores_de_equipo(array $jugadores, int $equipoId): array
{
    return array_values(array_filter($jugadores, fn($j) => (int) $j['equipo_id'] === $equipoId));
}

// ---------------------------------------------------------------------------
// Inscripción y carnet.
//
// El capitán inscribe a sus jugadores (quedan "pendiente") y el organizador los aprueba:
// recién ahí el jugador puede alinearse, sale en la nómina y se le asigna su número de
// carnet. Si el capitán cambia los datos que identifican a un jugador ya aprobado (nombre,
// documento, fecha de nacimiento o foto), vuelve a quedar pendiente: así nadie puede
// "reciclar" un carnet aprobado para otra persona.
// ---------------------------------------------------------------------------

const INSCRIPCION_PENDIENTE = 'pendiente';
const INSCRIPCION_INSCRITO = 'inscrito';

function jugador_inscrito(?array $jugador): bool
{
    return $jugador !== null && ($jugador['inscripcion_estado'] ?? '') !== INSCRIPCION_PENDIENTE;
}

/**
 * ¿Puede jugar? Activo y con la inscripción aprobada.
 */
function jugador_habilitado_plantilla(?array $jugador): bool
{
    return $jugador !== null && !empty($jugador['activo']) && jugador_inscrito($jugador);
}

/**
 * DPI/CUI o pasaporte normalizado: sin espacios ni guiones, en mayúsculas. Así "2345 67890
 * 0101" y "2345-67890-0101" son el mismo documento al buscar duplicados.
 */
function documento_normalizar(string $documento): string
{
    return mb_strtoupper((string) preg_replace('/[\s\-.]+/', '', trim($documento)));
}

/**
 * Valida el documento: 5 a 20 letras o números (el CUI son 13 dígitos; un extranjero
 * puede traer pasaporte). Devuelve el mensaje de error o ''.
 */
function documento_error(string $normalizado): string
{
    if ($normalizado === '') {
        return 'El documento de identificación (DPI/CUI o pasaporte) es obligatorio.';
    }
    if (!preg_match('/^[A-Z0-9]{5,20}$/', $normalizado)) {
        return 'El documento solo puede llevar letras y números (entre 5 y 20).';
    }
    if (ctype_digit($normalizado) && strlen($normalizado) !== 13 && strlen($normalizado) < 9) {
        return 'Revisa el documento: un DPI/CUI tiene 13 dígitos.';
    }
    return '';
}

/**
 * Valida la fecha de nacimiento (AAAA-MM-DD): fecha real, no futura, edad entre 4 y 90.
 */
function fecha_nacimiento_error(string $fecha): string
{
    if ($fecha === '') {
        return 'La fecha de nacimiento es obligatoria.';
    }
    $dt = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    if ($dt === false || $dt->format('Y-m-d') !== $fecha) {
        return 'La fecha de nacimiento no es válida.';
    }
    $edad = $dt->diff(new DateTimeImmutable('today'))->y;
    if ($dt > new DateTimeImmutable('today') || $edad < 4 || $edad > 90) {
        return 'Revisa la fecha de nacimiento: la edad tiene que estar entre 4 y 90 años.';
    }
    return '';
}

function jugador_edad(?array $jugador): ?int
{
    $fecha = (string) ($jugador['fecha_nacimiento'] ?? '');
    $dt = $fecha !== '' ? DateTimeImmutable::createFromFormat('!Y-m-d', $fecha) : false;
    return $dt ? $dt->diff(new DateTimeImmutable('today'))->y : null;
}

/**
 * Otro jugador de la MISMA copa con ese documento (en cualquier equipo), o null. Es el
 * candado contra el jugador "prestado" que aparece inscrito en dos equipos.
 */
function jugador_con_documento(array $jugadoresCopa, string $normalizado, int $excluirId = 0): ?array
{
    if ($normalizado === '') {
        return null;
    }
    foreach ($jugadoresCopa as $j) {
        if ((int) $j['id'] !== $excluirId && documento_normalizar((string) ($j['documento'] ?? '')) === $normalizado) {
            return $j;
        }
    }
    return null;
}

/**
 * Lo que le falta a un jugador para poder aprobarlo ('' si está completo).
 */
function inscripcion_faltante(array $jugador): string
{
    $faltan = [];
    if (trim((string) ($jugador['documento'] ?? '')) === '') {
        $faltan[] = 'documento';
    }
    if (trim((string) ($jugador['fecha_nacimiento'] ?? '')) === '') {
        $faltan[] = 'fecha de nacimiento';
    }
    if (trim((string) ($jugador['foto'] ?? '')) === '') {
        $faltan[] = 'foto';
    }
    return implode(', ', $faltan);
}

/**
 * Prefijo del número de carnet según el deporte de la copa.
 */
function carnet_prefijo(array $torneo): string
{
    return match ($torneo['deporte'] ?? '') {
        'futbol' => 'FUT',
        'voleibol' => 'VOL',
        default => 'BAS',
    };
}

/**
 * Le asigna número de carnet y código del QR a los jugadores indicados que todavía no los
 * tienen. Modifica $jugadoresCopa y devuelve cuántos se asignaron. El número es
 * correlativo dentro de la copa: PREFIJO-AÑO-0001, PREFIJO-AÑO-0002...
 *
 * @param array<int> $ids
 */
function carnet_asignar(array &$jugadoresCopa, array $ids, array $torneo): int
{
    $mayor = 0;
    foreach ($jugadoresCopa as $j) {
        if (preg_match('/-(\d+)$/', (string) ($j['carnet_numero'] ?? ''), $m)) {
            $mayor = max($mayor, (int) $m[1]);
        }
    }
    $anio = preg_match('/\d{4}/', (string) ($torneo['temporada'] ?? ''), $mA) ? $mA[0] : date('Y');
    $prefijo = carnet_prefijo($torneo) . '-' . $anio . '-';

    $asignados = 0;
    foreach ($jugadoresCopa as &$j) {
        if (!in_array((int) $j['id'], $ids, true)) {
            continue;
        }
        if (($j['carnet_numero'] ?? '') === '') {
            $mayor++;
            $j['carnet_numero'] = $prefijo . str_pad((string) $mayor, 4, '0', STR_PAD_LEFT);
            $asignados++;
        }
        if (($j['carnet_token'] ?? '') === '') {
            $j['carnet_token'] = bin2hex(random_bytes(12));
        }
    }
    unset($j);

    return $asignados;
}

/**
 * Jugador por el código de su carnet (el del QR), dentro de una copa.
 */
function jugador_por_carnet_token(array $jugadoresCopa, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{24}$/', $token)) {
        return null;
    }
    foreach ($jugadoresCopa as $j) {
        if (hash_equals((string) ($j['carnet_token'] ?? ''), $token)) {
            return $j;
        }
    }
    return null;
}
