<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Licencia de cada copa o liga vendida.
//
// El modelo de cobro es por campeonato: el organizador compra uno, lo juega y al terminar
// la copa queda en SOLO LECTURA. El sitio público, la tabla y el podio siguen visibles
// (son la vitrina de la plataforma), pero el panel ya no deja escribir nada. Así una copa
// pagada no se puede "reciclar" como temporada 2 renombrando equipos.
//
// Una copa deja de ser editable por dos caminos:
//   - el organizador la CIERRA al terminar (cerrado_en), lo que además publica el podio;
//   - se pasa la fecha de su licencia (licencia_hasta) más unos días de gracia, aunque no
//     la haya cerrado.
//
// licencia_hasta NULL = sin límite: las copas que ya existían antes de este cambio y las
// del super-admin. Solo el super-admin puede mover la fecha o reabrir una copa.
//
// Funciones puras (no tocan la base): se prueban en scripts/tests.php.
// ---------------------------------------------------------------------------

/** Cuánto dura la licencia de una copa nueva, contado desde el día en que se crea. */
const LICENCIA_MESES_POR_DEFECTO = 6;

/** Días extra después de licencia_hasta para terminar de cargar resultados. */
const LICENCIA_DIAS_GRACIA = 15;

/** Con cuántos días de anticipación se avisa en el panel que la licencia está por vencer. */
const LICENCIA_DIAS_AVISO = 15;

const LICENCIA_ACTIVA = 'activa';
const LICENCIA_POR_VENCER = 'por_vencer';
const LICENCIA_EN_GRACIA = 'en_gracia';
const LICENCIA_VENCIDA = 'vencida';
const LICENCIA_FINALIZADA = 'finalizada';

/**
 * Fecha de vencimiento para una copa que se crea hoy (Y-m-d).
 */
function licencia_hasta_por_defecto(?string $hoy = null): string
{
    $base = new DateTimeImmutable($hoy ?? date('Y-m-d'));
    return $base->modify('+' . LICENCIA_MESES_POR_DEFECTO . ' months')->format('Y-m-d');
}

/**
 * Último día en que todavía se puede escribir (licencia + gracia), o null si no hay límite.
 */
function licencia_ultimo_dia_editable(array $torneo): ?string
{
    $hasta = (string) ($torneo['licencia_hasta'] ?? '');
    if ($hasta === '') {
        return null;
    }
    return (new DateTimeImmutable($hasta))->modify('+' . LICENCIA_DIAS_GRACIA . ' days')->format('Y-m-d');
}

/**
 * Estado de la licencia de la copa en la fecha $hoy (Y-m-d).
 *
 * activa      → todo normal
 * por_vencer  → faltan LICENCIA_DIAS_AVISO días o menos para licencia_hasta
 * en_gracia   → ya pasó licencia_hasta, pero sigue dentro de los días de gracia
 * vencida     → pasó la gracia: solo lectura
 * finalizada  → el organizador cerró el campeonato: solo lectura
 */
function licencia_estado(array $torneo, ?string $hoy = null): string
{
    if (!empty($torneo['cerrado_en'])) {
        return LICENCIA_FINALIZADA;
    }
    $hasta = (string) ($torneo['licencia_hasta'] ?? '');
    if ($hasta === '') {
        return LICENCIA_ACTIVA;
    }
    $hoy ??= date('Y-m-d');
    if ($hoy > (string) licencia_ultimo_dia_editable($torneo)) {
        return LICENCIA_VENCIDA;
    }
    if ($hoy > $hasta) {
        return LICENCIA_EN_GRACIA;
    }
    $aviso = (new DateTimeImmutable($hasta))->modify('-' . LICENCIA_DIAS_AVISO . ' days')->format('Y-m-d');
    return $hoy >= $aviso ? LICENCIA_POR_VENCER : LICENCIA_ACTIVA;
}

/**
 * ¿Se puede escribir en esta copa desde el panel? El super-admin siempre puede (soporte,
 * correcciones, reabrir).
 */
function torneo_editable(array $torneo, bool $esSuperadmin = false, ?string $hoy = null): bool
{
    if ($esSuperadmin) {
        return true;
    }
    return !in_array(licencia_estado($torneo, $hoy), [LICENCIA_VENCIDA, LICENCIA_FINALIZADA], true);
}

/**
 * La fecha de fin que pide el organizador no puede pasar de su licencia. Devuelve el
 * mensaje de error, o '' si la fecha es válida. Sin licencia (NULL) no hay tope.
 */
function licencia_validar_fecha_fin(string $fechaFin, ?string $licenciaHasta): string
{
    if ($fechaFin === '' || $licenciaHasta === null || $licenciaHasta === '') {
        return '';
    }
    if ($fechaFin > $licenciaHasta) {
        return 'La fecha de fin no puede pasar del ' . licencia_fecha_legible($licenciaHasta)
            . ', que es hasta donde cubre tu licencia. Si necesitas más tiempo, comunícate con '
            . CONTACTO_PLATAFORMA . '.';
    }
    return '';
}

/**
 * Mensaje que ve quien intenta modificar una copa en solo lectura.
 */
function licencia_mensaje_solo_lectura(array $torneo): string
{
    $motivo = licencia_estado($torneo) === LICENCIA_FINALIZADA
        ? 'Este campeonato ya se cerró'
        : 'La licencia de este campeonato venció el ' . licencia_fecha_legible((string) $torneo['licencia_hasta']);

    return $motivo . ' y quedó en solo lectura: el sitio público y el podio siguen visibles. '
        . 'Para una nueva temporada o más tiempo, comunícate con MJ Control Systems (' . CONTACTO_PLATAFORMA . ').';
}

/** 2026-12-13 → 13/12/2026 */
function licencia_fecha_legible(string $fecha): string
{
    $d = DateTimeImmutable::createFromFormat('Y-m-d', substr($fecha, 0, 10));
    return $d ? $d->format('d/m/Y') : $fecha;
}
