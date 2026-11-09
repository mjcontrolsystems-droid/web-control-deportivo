<?php
/**
 * Panel de usuarios (solo super-admin). La tabla usa data-tabla-datos: búsqueda, cuántos
 * ver por página, orden por columna y paginación (public/assets/js/tabla-datos.js).
 */
$fechaHora = function (string $valor): string {
    if ($valor === '') {
        return '';
    }
    $ts = strtotime($valor);
    return $ts === false ? '' : date('d/m/Y H:i', $ts);
};
$iniciales = function (string $texto): string {
    $partes = preg_split('/[\s@._-]+/', trim($texto)) ?: [];
    $ini = '';
    foreach (array_slice(array_filter($partes), 0, 2) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $ini !== '' ? $ini : '?';
};
$claseEstado = [
    USUARIO_ESTADO_ACTIVO => 'text-bg-success',
    USUARIO_ESTADO_SUSPENDIDO => 'text-bg-danger',
    USUARIO_ESTADO_PENDIENTE => 'text-bg-secondary',
];
$claseRol = [
    USUARIO_ROL_SUPERADMIN => 'text-bg-dark',
    USUARIO_ROL_ORGANIZADOR => 'text-bg-primary',
    USUARIO_ROL_COLABORADOR => 'text-bg-light border',
];
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h3 class="mb-1">Usuarios</h3>
        <p class="text-muted small mb-0">Quién puede entrar, cuántas copas tiene autorizadas y el código de cada una. El registro público está cerrado: solo entran los correos de esta lista.</p>
    </div>
    <button type="button" class="btn btn-degradado rounded-pill px-3" data-bs-toggle="modal" data-bs-target="#modalAgregarUsuario">
        <i class="bi bi-person-plus me-1"></i>Agregar usuario
    </button>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="stat-tile text-center"><span class="stat-icono"><i class="bi bi-people"></i></span><div class="fs-4 fw-bold"><?= (int) $resumen['total'] ?></div><div class="small text-muted">Usuarios</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-tile text-center"><span class="stat-icono"><i class="bi bi-person-check text-success"></i></span><div class="fs-4 fw-bold"><?= (int) $resumen['activos'] ?></div><div class="small text-muted">Activos</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-tile text-center"><span class="stat-icono"><i class="bi bi-hourglass-split"></i></span><div class="fs-4 fw-bold"><?= (int) $resumen['pendientes'] ?></div><div class="small text-muted">Aún no entran</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-tile text-center"><span class="stat-icono"><i class="bi bi-person-slash text-danger"></i></span><div class="fs-4 fw-bold"><?= (int) $resumen['suspendidos'] ?></div><div class="small text-muted">Suspendidos</div></div></div>
</div>

<div class="card-suave p-3 p-md-4">
    <?php if (empty($filas)): ?>
        <p class="text-muted mb-0">Todavía no hay usuarios. Agrega el primero con el botón de arriba.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table align-middle mb-0" data-tabla-datos data-por-pagina="10">
            <thead>
                <tr class="small text-muted">
                    <th data-ordenable>Usuario</th>
                    <th data-ordenable>Rol</th>
                    <th data-ordenable>Estado</th>
                    <th data-ordenable class="text-center">Copas</th>
                    <th data-ordenable>Último ingreso</th>
                    <th data-ordenable>Alta</th>
                    <th class="text-end">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filas as $i => $f):
                    $esSuper = $f['rol'] === USUARIO_ROL_SUPERADMIN;
                    $sinCupo = $f['limite'] !== null && $f['usadas'] >= $f['limite'];
                ?>
                <tr>
                    <td data-orden="<?= e(mb_strtolower($f['nombre'] ?: $f['email'])) ?>">
                        <div class="d-flex align-items-center gap-2">
                            <span class="rounded-circle d-inline-flex align-items-center justify-content-center fw-bold small flex-shrink-0" style="width:38px;height:38px;background:var(--color-primario);color:#fff;"><?= e($iniciales($f['nombre'] ?: $f['email'])) ?></span>
                            <div style="min-width:0;">
                                <div class="fw-semibold text-truncate"><?= $f['nombre'] !== '' ? e($f['nombre']) : '<span class="text-muted fst-italic">Sin nombre aún</span>' ?></div>
                                <div class="small text-muted text-truncate"><?= e($f['email']) ?></div>
                            </div>
                        </div>
                    </td>
                    <td><span class="badge rounded-pill <?= $claseRol[$f['rol']] ?>"><?= e(USUARIO_ROLES_LABEL[$f['rol']]) ?></span></td>
                    <td><span class="badge rounded-pill <?= $claseEstado[$f['estado']] ?>"><?= e(USUARIO_ESTADOS_LABEL[$f['estado']]) ?></span></td>
                    <td class="text-center" data-orden="<?= (int) $f['usadas'] ?>">
                        <?php if ($esSuper): ?>
                            <span class="badge rounded-pill text-bg-light border"><?= count($f['copas']) ?> · sin límite</span>
                        <?php else: ?>
                            <span class="badge rounded-pill <?= $sinCupo ? 'text-bg-warning' : 'text-bg-light border' ?>"><?= (int) $f['usadas'] ?> / <?= (int) $f['limite'] ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="small" data-orden="<?= e($f['ultimo_acceso']) ?>"><?= $f['ultimo_acceso'] !== '' ? e($fechaHora($f['ultimo_acceso'])) : '<span class="text-muted">Nunca</span>' ?></td>
                    <td class="small" data-orden="<?= e($f['alta']) ?>"><?= e($fechaHora($f['alta'])) ?></td>
                    <td class="text-end text-nowrap">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#modalCopas<?= $i ?>" title="Ver sus copas y códigos">
                            <i class="bi bi-trophy"></i> <?= count($f['copas']) ?>
                        </button>
                        <?php if (!$esSuper): ?>
                        <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalCupo<?= $i ?>" title="Cambiar cupo de copas"><i class="bi bi-ticket-perforated"></i></button>
                        <?php if ($f['usuario_id'] !== null): ?>
                        <form method="post" class="d-inline" data-confirm="<?= $f['estado'] === USUARIO_ESTADO_SUSPENDIDO
                            ? '¿Reactivar a ' . e($f['email']) . '? Podrá volver a entrar al panel.'
                            : '¿Suspender a ' . e($f['email']) . '? Se le cierra la sesión y no podrá entrar al panel. Sus copas siguen visibles al público.' ?>">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="accion" value="<?= $f['estado'] === USUARIO_ESTADO_SUSPENDIDO ? 'reactivar' : 'suspender' ?>">
                            <input type="hidden" name="usuario_id" value="<?= (int) $f['usuario_id'] ?>">
                            <?php if ($f['estado'] === USUARIO_ESTADO_SUSPENDIDO): ?>
                            <button type="submit" class="btn btn-sm btn-outline-success" title="Reactivar"><i class="bi bi-person-check"></i></button>
                            <?php else: ?>
                            <button type="submit" class="btn btn-sm btn-outline-warning" title="Suspender"><i class="bi bi-person-slash"></i></button>
                            <?php endif; ?>
                        </form>
                        <?php endif; ?>
                        <?php if ($f['correo_id'] !== null): ?>
                        <form method="post" class="d-inline" data-confirm="¿Quitar a <?= e($f['email']) ?> de la lista de autorizados? Si no tiene cuenta, ya no podrá crearla. Su cuenta y sus copas (si tiene) no se borran.">
                            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="accion" value="eliminar">
                            <input type="hidden" name="email" value="<?= e($f['email']) ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Quitar autorización"><i class="bi bi-trash"></i></button>
                        </form>
                        <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<p class="text-muted small mt-2 mb-0">
    <i class="bi bi-info-circle me-1"></i>El cupo cuenta las copas creadas en total (también las borradas). Suspender a alguien no borra nada: sus copas siguen en línea y se reactiva con un clic.
</p>

<?php // ---------- Modales (fuera de la tabla: la paginación oculta filas, no modales) ---------- ?>

<div class="modal fade" id="modalAgregarUsuario" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="accion" value="agregar">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-person-plus me-1"></i>Agregar usuario</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <label class="form-label small fw-semibold" for="nuevoEmail">Correo de Google del cliente</label>
                <input type="email" name="email" id="nuevoEmail" class="form-control mb-3" placeholder="cliente@gmail.com" required>
                <label class="form-label small fw-semibold" for="nuevoCupo">Copas que compró</label>
                <input type="number" name="limite_torneos" id="nuevoCupo" class="form-control" value="<?= LIMITE_TORNEOS_POR_DEFECTO ?>" min="0" max="999">
                <div class="form-text">Entra con "Continuar con Google" usando ese correo. Si el envío de correos está configurado, se le avisa automáticamente.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary rounded-pill" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" class="btn btn-degradado rounded-pill px-3"><i class="bi bi-check-lg me-1"></i>Autorizar</button>
            </div>
        </form>
    </div>
</div>

<?php foreach ($filas as $i => $f): ?>
<?php if ($f['rol'] !== USUARIO_ROL_SUPERADMIN): ?>
<div class="modal fade" id="modalCupo<?= $i ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <form method="post" class="modal-content">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="accion" value="actualizar_limite">
            <input type="hidden" name="email" value="<?= e($f['email']) ?>">
            <div class="modal-header">
                <h5 class="modal-title">Cupo de copas</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="small text-muted mb-2"><?= e($f['email']) ?></div>
                <div class="small mb-3">Ha creado <strong><?= (int) $f['usadas'] ?></strong> en total.</div>
                <label class="form-label small fw-semibold" for="cupo<?= $i ?>">Copas autorizadas</label>
                <input type="number" name="limite_torneos" id="cupo<?= $i ?>" class="form-control" value="<?= (int) $f['limite'] ?>" min="0" max="999">
                <div class="form-text">Para venderle otra temporada, súbelo en 1.</div>
            </div>
            <div class="modal-footer">
                <button type="submit" class="btn btn-degradado rounded-pill px-3 w-100"><i class="bi bi-check-lg me-1"></i>Guardar cupo</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="modal fade" id="modalCopas<?= $i ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-trophy me-1"></i>Copas de <?= e($f['nombre'] ?: $f['email']) ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($f['copas'])): ?>
                    <p class="text-muted mb-0">Todavía no ha creado ninguna copa o liga.</p>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr class="small text-muted"><th>Copa o liga</th><th>Código</th><th>Licencia</th><th class="text-end"></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($f['copas'] as $copa): $estadoLic = licencia_estado($copa); ?>
                            <tr>
                                <td>
                                    <div class="fw-semibold"><?= e($copa['nombre']) ?></div>
                                    <div class="small text-muted"><?= e(DEPORTES[$copa['deporte']]['nombre'] ?? $copa['deporte']) ?>
                                        <?php if ($estadoLic === LICENCIA_FINALIZADA): ?> · <span class="text-dark fw-semibold">Finalizada</span>
                                        <?php elseif ($estadoLic === LICENCIA_VENCIDA): ?> · <span class="text-danger fw-semibold">Vencida</span><?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-nowrap">
                                    <code class="fw-bold fs-6"><?= e((string) $copa['codigo']) ?></code>
                                    <button type="button" class="btn btn-sm btn-link p-0 ms-1 btn-copiar-url" data-url="<?= e((string) $copa['codigo']) ?>" title="Copiar código"><i class="bi bi-clipboard"></i></button>
                                    <form method="post" class="d-inline" data-confirm="¿Generar un código nuevo para &quot;<?= e($copa['nombre']) ?>&quot;? El código actual dejará de funcionar.">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="accion" value="regenerar_codigo">
                                        <input type="hidden" name="torneo_id" value="<?= (int) $copa['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-link p-0 ms-1 text-muted" title="Generar código nuevo"><i class="bi bi-arrow-repeat"></i></button>
                                    </form>
                                </td>
                                <td>
                                    <form method="post" class="d-flex align-items-center gap-1 mb-0">
                                        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                                        <input type="hidden" name="accion" value="fijar_licencia">
                                        <input type="hidden" name="torneo_id" value="<?= (int) $copa['id'] ?>">
                                        <input type="date" name="licencia_hasta" class="form-control form-control-sm" style="max-width:150px;" value="<?= e((string) ($copa['licencia_hasta'] ?? '')) ?>" title="Vacío = sin límite">
                                        <button type="submit" class="btn btn-sm btn-outline-secondary" title="Guardar licencia"><i class="bi bi-check-lg"></i></button>
                                    </form>
                                </td>
                                <td class="text-end">
                                    <a href="<?= e(url_copa_de($copa)) ?>" target="_blank" class="btn btn-sm btn-outline-secondary" title="Ver sitio público"><i class="bi bi-box-arrow-up-right"></i></a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="small text-muted mt-2 mb-0">La fecha de licencia vacía significa sin límite. Para reabrir una copa finalizada usa "Mis copas".</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
