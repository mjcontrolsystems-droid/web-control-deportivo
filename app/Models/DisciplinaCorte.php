<?php
declare(strict_types=1);

/**
 * Limpiezas de tarjetas ("borrón y cuenta nueva").
 *
 * Una limpieza NO borra tarjetas ni multas: el historial de cada partido, la tabla de
 * disciplina por temporada y el dinero recaudado quedan intactos. Lo único que hace es
 * marcar un corte: lo jugado hasta `hasta_fecha` deja de contar para acumular amarillas
 * (modo 'amarillas') o, además, para las suspensiones pendientes (modo 'todo').
 * La regla en sí está en app/Support/disciplina.php (disciplina_corte_*).
 */

const DISCIPLINA_CORTE_MODOS = ['amarillas', 'todo'];

/**
 * Limpiezas de la copa, de la más reciente a la más antigua.
 * Se guardan en memoria por petición: el cálculo de suspensiones se pide muchas veces
 * en una misma pantalla (una por partido) y no tiene sentido ir a la base cada vez.
 */
function disciplina_cortes_listar(int $torneoId, bool $refrescar = false): array
{
    static $cache = [];
    if (!$refrescar && isset($cache[$torneoId])) {
        return $cache[$torneoId];
    }
    try {
        $stmt = db_conexion()->prepare(
            'SELECT id, torneo_id, equipo_id, hasta_fecha, modo, creado_por, creado_en
               FROM disciplina_cortes WHERE torneo_id = :t ORDER BY hasta_fecha DESC, id DESC'
        );
        $stmt->bindValue(':t', $torneoId, PDO::PARAM_INT);
        $stmt->execute();
        $filas = $stmt->fetchAll();
    } catch (Throwable $e) {
        // Si la tabla aún no existe (migración pendiente) se comporta como "sin limpiezas".
        error_log('disciplina_cortes_listar: ' . $e->getMessage());
        $filas = [];
    }
    foreach ($filas as &$f) {
        $f['equipo_id'] = $f['equipo_id'] !== null ? (int) $f['equipo_id'] : null;
        $f['hasta_fecha'] = substr((string) $f['hasta_fecha'], 0, 10);
    }
    unset($f);
    return $cache[$torneoId] = $filas;
}

function disciplina_corte_crear(int $torneoId, ?int $equipoId, string $hastaFecha, string $modo, ?int $usuarioId): bool
{
    if (!in_array($modo, DISCIPLINA_CORTE_MODOS, true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hastaFecha)) {
        return false;
    }
    try {
        $stmt = db_conexion()->prepare(
            'INSERT INTO disciplina_cortes (torneo_id, equipo_id, hasta_fecha, modo, creado_por)
             VALUES (:t, :e, :f, :m, :u)'
        );
        $stmt->bindValue(':t', $torneoId, PDO::PARAM_INT);
        $stmt->bindValue(':e', $equipoId, $equipoId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':f', $hastaFecha);
        $stmt->bindValue(':m', $modo);
        $stmt->bindValue(':u', $usuarioId, $usuarioId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();
        disciplina_cortes_listar($torneoId, true);
        return true;
    } catch (Throwable $e) {
        error_log('disciplina_corte_crear: ' . $e->getMessage());
        return false;
    }
}

/** Deshacer una limpieza: las tarjetas de antes vuelven a contar. */
function disciplina_corte_eliminar(int $id, int $torneoId): bool
{
    try {
        $stmt = db_conexion()->prepare('DELETE FROM disciplina_cortes WHERE id = :id AND torneo_id = :t');
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->bindValue(':t', $torneoId, PDO::PARAM_INT);
        $stmt->execute();
        disciplina_cortes_listar($torneoId, true);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('disciplina_corte_eliminar: ' . $e->getMessage());
        return false;
    }
}
