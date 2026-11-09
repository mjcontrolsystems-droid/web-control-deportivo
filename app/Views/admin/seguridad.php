<?php
/**
 * Panel de Seguridad: resumen, IPs sospechosas, IPs bloqueadas y el registro completo.
 */
$claseNivel = [SEG_INFO => 'text-bg-light border', SEG_AVISO => 'text-bg-warning', SEG_ALERTA => 'text-bg-danger'];
$formBloquear = function (string $ip, string $motivo) use ($miIp): string {
    if ($ip === $miIp) {
        return '<span class="badge rounded-pill text-bg-info" title="Es la IP desde la que estás conectado">Tu IP</span>';
    }
    return '<form method="post" class="d-inline" data-confirm="¿Bloquear la IP ' . e($ip) . '? No podrá abrir ninguna página del sitio hasta que la desbloquees.">'
        . '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">'
        . '<input type="hidden" name="accion" value="bloquear_ip">'
        . '<input type="hidden" name="ip" value="' . e($ip) . '">'
        . '<input type="hidden" name="motivo" value="' . e($motivo) . '">'
        . '<button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-slash-circle me-1"></i>Bloquear</button></form>';
};
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h3 class="mb-1">Seguridad</h3>
        <p class="text-muted small mb-0">Quién intentó entrar, desde dónde y qué se bloqueó. Se guardan los últimos <?= SEG_DIAS_RETENCION ?> días.</p>
    </div>
    <div class="small text-muted text-end">
        Tu IP ahora: <code><?= e($miIp) ?></code><br>
        <?php if ($webhookActivo): ?>
            <span class="text-success"><i class="bi bi-bell-fill me-1"></i>Las alertas se avisan al instante a tu webhook</span>
        <?php else: ?>
            <span title="Configura ERROR_WEBHOOK_URL en Render (Discord o Slack) para recibir las alertas en el momento"><i class="bi bi-bell-slash me-1"></i>Sin avisos inmediatos (falta ERROR_WEBHOOK_URL)</span>
        <?php endif; ?>
    </div>
</div>

<?php // ---------- Filtros ---------- ?>
<form method="get" class="card-suave p-3 mb-4 row g-2 align-items-end mx-0">
    <div class="col-6 col-md-3">
        <label class="form-label small fw-semibold mb-1" for="segPeriodo">Periodo</label>
        <select name="periodo" id="segPeriodo" class="form-select form-select-sm">
            <?php foreach (SEG_PERIODOS as $clave => [$nombre]): ?>
            <option value="<?= e($clave) ?>" <?= $filtros['periodo'] === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-6 col-md-2">
        <label class="form-label small fw-semibold mb-1" for="segNivel">Nivel</label>
        <select name="nivel" id="segNivel" class="form-select form-select-sm">
            <option value="">Todos</option>
            <?php foreach (SEG_NIVELES_LABEL as $clave => $nombre): ?>
            <option value="<?= e($clave) ?>" <?= $filtros['nivel'] === $clave ? 'selected' : '' ?>><?= e($nombre) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-12 col-md-3">
        <label class="form-label small fw-semibold mb-1" for="segTipo">Evento</label>
        <select name="tipo" id="segTipo" class="form-select form-select-sm">
            <option value="">Todos</option>
            <?php foreach (SEG_TIPOS as $clave => $def): ?>
            <option value="<?= e($clave) ?>" <?= $filtros['tipo'] === $clave ? 'selected' : '' ?>><?= e($def['nombre']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-8 col-md-2">
        <label class="form-label small fw-semibold mb-1" for="segIp">IP</label>
        <input type="text" name="ip" id="segIp" class="form-control form-control-sm" placeholder="Todas" value="<?= e($filtros['ip']) ?>">
    </div>
    <div class="col-4 col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-sm btn-degradado rounded-pill px-3"><i class="bi bi-funnel"></i></button>
        <a href="<?= e(url('admin/seguridad.php')) ?>" class="btn btn-sm btn-outline-secondary rounded-pill px-3">Limpiar</a>
    </div>
</form>

<?php // ---------- Cifras ---------- ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="stat-tile text-center"><span class="stat-icono"><i class="bi bi-box-arrow-in-right text-success"></i></span><div class="fs-4 fw-bold"><?= (int) $resumen['logins_ok'] ?></div><div class="small text-muted">Inicios de sesión</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-tile text-center"><span class="stat-icono"><i class="bi bi-x-octagon text-warning"></i></span><div class="fs-4 fw-bold"><?= (int) $resumen['fallidos'] ?></div><div class="small text-muted">Intentos fallidos</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-tile text-center"><span class="stat-icono"><i class="bi bi-shield-exclamation text-danger"></i></span><div class="fs-4 fw-bold"><?= (int) $resumen['alertas'] ?></div><div class="small text-muted">Alertas</div></div></div>
    <div class="col-6 col-md-3"><div class="stat-tile text-center"><span class="stat-icono"><i class="bi bi-globe2"></i></span><div class="fs-4 fw-bold"><?= (int) $resumen['ips'] ?></div><div class="small text-muted">IPs distintas</div></div></div>
</div>

<?php // ---------- Ahora mismo ---------- ?>
<?php if (!empty($ipsFrenadas) || !empty($ipsBloqueadas)): ?>
<div class="row g-3 mb-4">
    <?php if (!empty($ipsFrenadas)): ?>
    <div class="col-lg-6">
        <div class="card-suave p-3 h-100 border border-warning">
            <h6 class="mb-2"><i class="bi bi-hourglass-split text-warning me-1"></i>Frenadas ahora por intentos de login</h6>
            <p class="small text-muted">El límite automático las detuvo (<?= LOGIN_MAX_INTENTOS ?> intentos en <?= LOGIN_VENTANA_SEGUNDOS ?> s). Se liberan solas; si insisten, bloquéalas.</p>
            <?php foreach ($ipsFrenadas as $f): ?>
            <div class="d-flex justify-content-between align-items-center border-top py-2 gap-2">
                <div><code><?= e($f['ip']) ?></code> <span class="small text-muted"><?= (int) $f['intentos'] ?> intentos</span></div>
                <?= isset($ipsBloqueadasSet[$f['ip']]) ? '<span class="badge text-bg-dark">Bloqueada</span>' : $formBloquear((string) $f['ip'], 'Fuerza bruta en el login') ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    <?php if (!empty($ipsBloqueadas)): ?>
    <div class="col-lg-6">
        <div class="card-suave p-3 h-100">
            <h6 class="mb-2"><i class="bi bi-slash-circle text-danger me-1"></i>IPs bloqueadas a mano (<?= count($ipsBloqueadas) ?>)</h6>
            <?php foreach ($ipsBloqueadas as $b): ?>
            <div class="d-flex justify-content-between align-items-center border-top py-2 gap-2">
                <div style="min-width:0;">
                    <a href="<?= e(url('admin/seguridad.php?periodo=90d&ip=' . rawurlencode((string) $b['ip']))) ?>"><code><?= e($b['ip']) ?></code></a>
                    <div class="small text-muted text-truncate"><?= e($b['motivo']) ?> · <?= e(seguridad_fecha_hora((string) $b['creado_en'])) ?></div>
                </div>
                <form method="post" class="mb-0" data-confirm="¿Desbloquear la IP <?= e($b['ip']) ?>?">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="accion" value="desbloquear_ip">
                    <input type="hidden" name="ip" value="<?= e($b['ip']) ?>">
                    <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="bi bi-unlock me-1"></i>Desbloquear</button>
                </form>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php // ---------- IPs sospechosas ---------- ?>
<div class="card-suave p-3 p-md-4 mb-4">
    <h5 class="mb-1"><i class="bi bi-incognito me-1"></i>IPs sospechosas</h5>
    <p class="small text-muted">Las que tuvieron intentos fallidos o alertas en el periodo. Una IP con muchos fallos y varios usuarios distintos es un ataque; una con 1 o 2 fallos y después un inicio de sesión exitoso es alguien que se equivocó de contraseña.</p>
    <?php if (empty($resumen['sospechosas'])): ?>
        <p class="text-muted mb-0"><i class="bi bi-check-circle text-success me-1"></i>Nada sospechoso en este periodo.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" data-tabla-datos data-por-pagina="10">
            <thead>
                <tr class="small text-muted">
                    <th data-ordenable>IP</th>
                    <th data-ordenable class="text-end">Alertas</th>
                    <th data-ordenable class="text-end">Fallidos</th>
                    <th data-ordenable class="text-end">Exitosos</th>
                    <th>Usuarios que probó</th>
                    <th data-ordenable>Último</th>
                    <th>Navegador</th>
                    <th class="text-end"></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($resumen['sospechosas'] as $s): ?>
                <tr>
                    <td><a href="<?= e(url('admin/seguridad.php?' . http_build_query(['periodo' => $filtros['periodo'], 'ip' => $s['ip']]))) ?>"><code><?= e($s['ip']) ?></code></a></td>
                    <td class="text-end"><?= $s['alertas'] > 0 ? '<span class="badge text-bg-danger">' . (int) $s['alertas'] . '</span>' : '0' ?></td>
                    <td class="text-end"><?= (int) $s['fallidos'] ?></td>
                    <td class="text-end"><?= (int) $s['ok'] ?></td>
                    <td class="small"><?= e(implode(', ', array_slice($s['usuarios'], 0, 5))) ?><?= count($s['usuarios']) > 5 ? ' y ' . (count($s['usuarios']) - 5) . ' más' : '' ?></td>
                    <td class="small" data-orden="<?= e($s['ultimo']) ?>"><?= e(seguridad_fecha_hora($s['ultimo'])) ?></td>
                    <td class="small"><?= e(seguridad_navegador_corto($s['agente'])) ?></td>
                    <td class="text-end"><?= isset($ipsBloqueadasSet[$s['ip']]) ? '<span class="badge text-bg-dark">Bloqueada</span>' : $formBloquear($s['ip'], 'Actividad sospechosa') ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php // ---------- Registro completo ---------- ?>
<div class="card-suave p-3 p-md-4">
    <h5 class="mb-3"><i class="bi bi-journal-text me-1"></i>Registro <span class="badge rounded-pill text-bg-light border small fw-normal"><?= count($eventos) ?></span></h5>
    <?php if (empty($eventos)): ?>
        <p class="text-muted mb-0">No hay eventos con estos filtros.</p>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0" data-tabla-datos data-por-pagina="25">
            <thead>
                <tr class="small text-muted">
                    <th data-ordenable>Fecha y hora</th>
                    <th data-ordenable>Nivel</th>
                    <th data-ordenable>Evento</th>
                    <th data-ordenable>Usuario</th>
                    <th data-ordenable>IP</th>
                    <th>Detalle</th>
                    <th>Navegador</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($eventos as $ev): $def = SEG_TIPOS[$ev['tipo']] ?? ['nombre' => $ev['tipo'], 'icono' => 'bi-dot']; ?>
                <tr>
                    <td class="small text-nowrap" data-orden="<?= e((string) $ev['creado_en']) ?>"><?= e(seguridad_fecha_hora((string) $ev['creado_en'])) ?></td>
                    <td><span class="badge rounded-pill <?= $claseNivel[$ev['nivel']] ?? 'text-bg-light' ?>"><?= e(SEG_NIVELES_LABEL[$ev['nivel']] ?? $ev['nivel']) ?></span></td>
                    <td class="small"><i class="bi <?= e($def['icono']) ?> me-1"></i><?= e($def['nombre']) ?></td>
                    <td class="small"><?= e((string) $ev['usuario']) ?></td>
                    <td><a href="<?= e(url('admin/seguridad.php?' . http_build_query(['periodo' => $filtros['periodo'], 'ip' => $ev['ip']]))) ?>"><code class="small"><?= e((string) $ev['ip']) ?></code></a></td>
                    <td class="small"><?= e((string) $ev['detalle']) ?><?php if ($ev['tipo'] === 'escaneo' || $ev['tipo'] === 'csrf_invalido'): ?><div class="text-muted text-break"><?= e((string) $ev['ruta']) ?></div><?php endif; ?></td>
                    <td class="small" title="<?= e((string) $ev['user_agent']) ?>"><?= e(seguridad_navegador_corto((string) $ev['user_agent'])) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
