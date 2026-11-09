<?php
declare(strict_types=1);

// ---------------------------------------------------------------------------
// Registro de seguridad: quién intentó entrar, desde dónde y qué pasó.
//
// La bitácora (bitacora.php) cuenta lo que HACE la gente con sesión dentro de una copa.
// Esto es otra cosa: lo que pasa en la PUERTA. Intentos de login fallidos, IPs que se
// bloquean por fuerza bruta, correos de Google rechazados, formularios con el token de
// seguridad falso, gente entrando a pantallas que no le tocan y robots buscando
// /wp-admin o /.env. Antes nada de eso quedaba guardado: los intentos de login se
// borraban a la hora y solo servían para el límite.
//
// Solo lo ve el super-admin (admin/seguridad.php). Los eventos de nivel "alerta" se
// avisan además al webhook de ERROR_WEBHOOK_URL (Discord o Slack), uno por IP y tipo por
// hora para no inundar el canal en pleno ataque.
// ---------------------------------------------------------------------------

const SEG_INFO = 'info';
const SEG_AVISO = 'aviso';
const SEG_ALERTA = 'alerta';

/** Días que se guarda el registro; lo más viejo se borra solo. */
const SEG_DIAS_RETENCION = 90;

const SEG_TIPOS = [
    'login_ok' => ['nombre' => 'Inicio de sesión', 'nivel' => SEG_INFO, 'icono' => 'bi-box-arrow-in-right'],
    'login_fallido' => ['nombre' => 'Login fallido', 'nivel' => SEG_AVISO, 'icono' => 'bi-x-octagon'],
    'login_bloqueado' => ['nombre' => 'IP bloqueada por intentos', 'nivel' => SEG_ALERTA, 'icono' => 'bi-shield-exclamation'],
    'google_rechazado' => ['nombre' => 'Google: correo no autorizado', 'nivel' => SEG_AVISO, 'icono' => 'bi-google'],
    'cuenta_suspendida' => ['nombre' => 'Cuenta suspendida intentó entrar', 'nivel' => SEG_AVISO, 'icono' => 'bi-person-slash'],
    'csrf_invalido' => ['nombre' => 'Formulario con token inválido', 'nivel' => SEG_ALERTA, 'icono' => 'bi-shield-x'],
    'acceso_denegado' => ['nombre' => 'Acceso denegado', 'nivel' => SEG_AVISO, 'icono' => 'bi-sign-stop'],
    'codigo_bloqueado' => ['nombre' => 'Búsqueda de códigos bloqueada', 'nivel' => SEG_ALERTA, 'icono' => 'bi-123'],
    'recuperar_password' => ['nombre' => 'Recuperación de contraseña', 'nivel' => SEG_INFO, 'icono' => 'bi-key'],
    'escaneo' => ['nombre' => 'Escaneo de rutas (robot)', 'nivel' => SEG_ALERTA, 'icono' => 'bi-bug'],
    'ip_bloqueada_manual' => ['nombre' => 'Petición de IP bloqueada', 'nivel' => SEG_ALERTA, 'icono' => 'bi-slash-circle'],
];

const SEG_NIVELES_LABEL = [SEG_INFO => 'Info', SEG_AVISO => 'Aviso', SEG_ALERTA => 'Alerta'];

/**
 * Rutas que ningún visitante normal pide en esta plataforma: son las que prueban los
 * robots que buscan WordPress, phpMyAdmin, respaldos o el .env con las contraseñas.
 */
const SEG_RUTAS_SOSPECHOSAS = [
    '/wp-', 'wp-login', 'wp-admin', 'xmlrpc.php', 'wp-content', 'wp-includes',
    '/.env', '/.git', '/.aws', '/.ssh', '/.htaccess', '/.htpasswd', '/.ds_store',
    'phpmyadmin', '/pma', '/adminer', '/phpinfo', 'info.php', '/server-status',
    '/config.php', '/configuration.php', '/vendor/', '/cgi-bin', '/shell', 'eval-stdin',
    '.sql', '.bak', '.old', '.backup', '.zip', '.tar', '.gz', 'composer.json', 'composer.lock',
    '/etc/passwd', '../', '%2e%2e', '/boaform', '/actuator', '/owa/', '/solr', '/console',
];

/** ¿La ruta pedida es de un robot buscando vulnerabilidades? */
function seguridad_es_escaneo(string $ruta): bool
{
    $r = mb_strtolower(rawurldecode($ruta));
    foreach (SEG_RUTAS_SOSPECHOSAS as $patron) {
        if (str_contains($r, $patron)) {
            return true;
        }
    }
    return false;
}

/** Navegador y sistema en pocas palabras ("Chrome · Android"), para la tabla. */
function seguridad_navegador_corto(string $agente): string
{
    if ($agente === '') {
        return '';
    }
    $a = mb_strtolower($agente);
    if (preg_match('/bot|crawl|spider|curl|wget|python|go-http|scan|nikto|sqlmap|httpclient|java\//', $a)) {
        return 'Robot / script';
    }
    $nav = match (true) {
        str_contains($a, 'edg/') => 'Edge',
        str_contains($a, 'opr/') || str_contains($a, 'opera') => 'Opera',
        str_contains($a, 'samsungbrowser') => 'Samsung',
        str_contains($a, 'chrome/') => 'Chrome',
        str_contains($a, 'firefox/') => 'Firefox',
        str_contains($a, 'safari/') => 'Safari',
        default => 'Otro',
    };
    $so = match (true) {
        str_contains($a, 'android') => 'Android',
        str_contains($a, 'iphone') || str_contains($a, 'ipad') => 'iPhone/iPad',
        str_contains($a, 'windows') => 'Windows',
        str_contains($a, 'mac os') => 'Mac',
        str_contains($a, 'linux') => 'Linux',
        default => '',
    };
    return $so !== '' ? "{$nav} · {$so}" : $nav;
}

/**
 * Guarda un evento. NUNCA lanza error: el registro de seguridad no puede tumbar el login
 * ni la página que lo llama (si la base falla, solo queda en el log del servidor).
 */
function seguridad_registrar(string $tipo, string $detalle = '', string $usuarioIntentado = '', ?int $usuarioId = null): void
{
    try {
        $nivel = SEG_TIPOS[$tipo]['nivel'] ?? SEG_AVISO;
        $ip = obtener_ip_cliente();
        $pdo = db_conexion();

        // Los robots piden cientos de rutas por minuto: de escaneos y de peticiones de una
        // IP ya bloqueada basta una fila por IP cada 10 minutos.
        if (in_array($tipo, ['escaneo', 'ip_bloqueada_manual'], true) && seguridad_hubo_reciente($tipo, $ip, 600)) {
            return;
        }
        // ¿Avisar al webhook? Solo alertas, y una por IP y tipo por hora.
        $avisar = $nivel === SEG_ALERTA && !seguridad_hubo_reciente($tipo, $ip, 3600);

        $stmt = $pdo->prepare(
            'INSERT INTO seguridad_eventos (tipo, nivel, ip, usuario, usuario_id, detalle, ruta, user_agent)
             VALUES (:tipo, :nivel, :ip, :usuario, :usuario_id, :detalle, :ruta, :agente)'
        );
        $stmt->bindValue(':tipo', $tipo, PDO::PARAM_STR);
        $stmt->bindValue(':nivel', $nivel, PDO::PARAM_STR);
        $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
        $stmt->bindValue(':usuario', mb_substr($usuarioIntentado, 0, 150), PDO::PARAM_STR);
        $stmt->bindValue(':usuario_id', $usuarioId, $usuarioId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':detalle', mb_substr($detalle, 0, 500), PDO::PARAM_STR);
        $stmt->bindValue(':ruta', mb_substr(($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''), 0, 300), PDO::PARAM_STR);
        $stmt->bindValue(':agente', mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 300), PDO::PARAM_STR);
        $stmt->execute();

        // Limpieza de lo viejo de vez en cuando (no en cada petición).
        if (random_int(1, 200) === 1) {
            $pdo->exec("DELETE FROM seguridad_eventos WHERE creado_en < now() - interval '" . SEG_DIAS_RETENCION . " days'");
        }

        if ($avisar) {
            seguridad_avisar_webhook($tipo, $ip, $usuarioIntentado, $detalle);
        }
    } catch (Throwable $e) {
        error_log('seguridad_registrar(' . $tipo . '): ' . $e->getMessage());
    }
}

function seguridad_hubo_reciente(string $tipo, string $ip, int $segundos): bool
{
    $stmt = db_conexion()->prepare(
        'SELECT 1 FROM seguridad_eventos WHERE tipo = :tipo AND ip = :ip AND creado_en > now() - make_interval(secs => :s) LIMIT 1'
    );
    $stmt->bindValue(':tipo', $tipo, PDO::PARAM_STR);
    $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
    $stmt->bindValue(':s', $segundos, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchColumn() !== false;
}

/** Aviso inmediato a Discord/Slack (mismo webhook que los errores). */
function seguridad_avisar_webhook(string $tipo, string $ip, string $usuario, string $detalle): void
{
    $webhook = getenv('ERROR_WEBHOOK_URL') ?: '';
    if ($webhook === '' || !function_exists('curl_init')) {
        return;
    }
    $mensaje = '🚨 Seguridad: ' . (SEG_TIPOS[$tipo]['nombre'] ?? $tipo)
        . "\nIP: {$ip}" . ($usuario !== '' ? "\nUsuario: {$usuario}" : '')
        . ($detalle !== '' ? "\nDetalle: {$detalle}" : '')
        . "\nRuta: " . (($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? ''))
        . "\nRevisa el panel → Seguridad.";
    $ch = curl_init($webhook);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode(['content' => $mensaje, 'text' => $mensaje]),
    ]);
    @curl_exec($ch);
    curl_close($ch);
}

// --- Bloqueo manual de IPs ---------------------------------------------------

/**
 * ¿Esta IP está bloqueada a mano por el super-admin? Se revisa al entrar al sitio.
 * Si la base falla, deja pasar: un bloqueo de IP nunca debe tumbar el sitio entero.
 */
function seguridad_ip_bloqueada(string $ip): bool
{
    try {
        $stmt = db_conexion()->prepare('SELECT 1 FROM ips_bloqueadas WHERE ip = :ip');
        $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function seguridad_bloquear_ip(string $ip, string $motivo, int $porUsuarioId): void
{
    $stmt = db_conexion()->prepare(
        'INSERT INTO ips_bloqueadas (ip, motivo, bloqueada_por) VALUES (:ip, :motivo, :por)
         ON CONFLICT (ip) DO UPDATE SET motivo = EXCLUDED.motivo'
    );
    $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
    $stmt->bindValue(':motivo', mb_substr($motivo, 0, 200), PDO::PARAM_STR);
    $stmt->bindValue(':por', $porUsuarioId, PDO::PARAM_INT);
    $stmt->execute();
}

function seguridad_desbloquear_ip(string $ip): void
{
    $stmt = db_conexion()->prepare('DELETE FROM ips_bloqueadas WHERE ip = :ip');
    $stmt->bindValue(':ip', $ip, PDO::PARAM_STR);
    $stmt->execute();
}

function seguridad_ips_bloqueadas(): array
{
    return db_conexion()->query('SELECT * FROM ips_bloqueadas ORDER BY creado_en DESC')->fetchAll();
}

/** IPs que ahora mismo están frenadas por el límite automático de intentos de login. */
function seguridad_ips_frenadas_login(): array
{
    $stmt = db_conexion()->prepare(
        'SELECT ip, COUNT(*) AS intentos, MAX(intentado_en) AS ultimo FROM intentos_login
         WHERE intentado_en > now() - make_interval(secs => :s) GROUP BY ip HAVING COUNT(*) >= :max ORDER BY ultimo DESC'
    );
    $stmt->bindValue(':s', LOGIN_VENTANA_SEGUNDOS, PDO::PARAM_INT);
    $stmt->bindValue(':max', LOGIN_MAX_INTENTOS, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// --- Consultas del panel --------------------------------------------------

const SEG_PERIODOS = ['24h' => ['Últimas 24 horas', 1], '7d' => ['Últimos 7 días', 7], '30d' => ['Últimos 30 días', 30], '90d' => ['Últimos 90 días', 90]];

/**
 * Eventos del periodo con los filtros del panel (más recientes primero, máx. 3000).
 *
 * @param array{periodo:string, nivel:string, tipo:string, ip:string} $f
 */
function seguridad_eventos(array $f): array
{
    $dias = SEG_PERIODOS[$f['periodo']][1] ?? 7;
    $sql = 'SELECT * FROM seguridad_eventos WHERE creado_en > now() - make_interval(days => :dias)';
    $params = [':dias' => [$dias, PDO::PARAM_INT]];
    if ($f['nivel'] !== '') {
        $sql .= ' AND nivel = :nivel';
        $params[':nivel'] = [$f['nivel'], PDO::PARAM_STR];
    }
    if ($f['tipo'] !== '') {
        $sql .= ' AND tipo = :tipo';
        $params[':tipo'] = [$f['tipo'], PDO::PARAM_STR];
    }
    if ($f['ip'] !== '') {
        $sql .= ' AND ip = :ip';
        $params[':ip'] = [$f['ip'], PDO::PARAM_STR];
    }
    $sql .= ' ORDER BY creado_en DESC, id DESC LIMIT 3000';
    $stmt = db_conexion()->prepare($sql);
    foreach ($params as $k => [$v, $t]) {
        $stmt->bindValue($k, $v, $t);
    }
    $stmt->execute();
    return $stmt->fetchAll();
}

/** Filtros del panel limpios. */
function seguridad_filtros(array $entrada): array
{
    $periodo = (string) ($entrada['periodo'] ?? '7d');
    $nivel = (string) ($entrada['nivel'] ?? '');
    $tipo = (string) ($entrada['tipo'] ?? '');
    $ip = trim((string) ($entrada['ip'] ?? ''));
    return [
        'periodo' => isset(SEG_PERIODOS[$periodo]) ? $periodo : '7d',
        'nivel' => isset(SEG_NIVELES_LABEL[$nivel]) ? $nivel : '',
        'tipo' => isset(SEG_TIPOS[$tipo]) ? $tipo : '',
        'ip' => filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '',
    ];
}

/**
 * Cifras y ranking de IPs sospechosas a partir de los eventos (función pura).
 *
 * @return array{logins_ok:int, fallidos:int, alertas:int, ips:int, sospechosas: array<int, array>}
 */
function seguridad_resumen(array $eventos): array
{
    $r = ['logins_ok' => 0, 'fallidos' => 0, 'alertas' => 0, 'ips' => 0, 'sospechosas' => []];
    $porIp = [];
    foreach ($eventos as $ev) {
        $tipo = (string) $ev['tipo'];
        $ip = (string) $ev['ip'];
        if ($tipo === 'login_ok') {
            $r['logins_ok']++;
        }
        if ($tipo === 'login_fallido' || $tipo === 'google_rechazado') {
            $r['fallidos']++;
        }
        if (($ev['nivel'] ?? '') === SEG_ALERTA) {
            $r['alertas']++;
        }
        $porIp[$ip] ??= ['ip' => $ip, 'fallidos' => 0, 'alertas' => 0, 'ok' => 0, 'usuarios' => [], 'ultimo' => '', 'agente' => ''];
        $p = &$porIp[$ip];
        if ($tipo === 'login_ok') {
            $p['ok']++;
        } elseif ($tipo === 'login_fallido' || $tipo === 'google_rechazado') {
            $p['fallidos']++;
        }
        if (($ev['nivel'] ?? '') === SEG_ALERTA) {
            $p['alertas']++;
        }
        if (($ev['usuario'] ?? '') !== '' && $tipo !== 'login_ok') {
            $p['usuarios'][(string) $ev['usuario']] = true;
        }
        if ((string) $ev['creado_en'] > $p['ultimo']) {
            $p['ultimo'] = (string) $ev['creado_en'];
            $p['agente'] = (string) ($ev['user_agent'] ?? '');
        }
        unset($p);
    }
    $r['ips'] = count($porIp);

    // Sospechosa = tuvo fallos o alertas. Primero las de más alertas, luego más fallos.
    $sospechosas = array_values(array_filter($porIp, fn($p) => $p['fallidos'] > 0 || $p['alertas'] > 0));
    foreach ($sospechosas as &$s) {
        $s['usuarios'] = array_keys($s['usuarios']);
    }
    unset($s);
    usort($sospechosas, fn($a, $b) => [$b['alertas'], $b['fallidos']] <=> [$a['alertas'], $a['fallidos']]);
    $r['sospechosas'] = array_slice($sospechosas, 0, 50);

    return $r;
}

/** Fecha y hora local (d/m/Y H:i:s) de un TIMESTAMPTZ. */
function seguridad_fecha_hora(string $marca): string
{
    if ($marca === '') {
        return '';
    }
    try {
        return (new DateTimeImmutable($marca))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('d/m/Y H:i:s');
    } catch (Throwable $e) {
        return $marca;
    }
}
