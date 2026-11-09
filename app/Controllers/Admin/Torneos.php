<?php
declare(strict_types=1);

auth_requerir();
$usuarioId = (int) $_SESSION['usuario_id'];

// Cupo de copas/ligas del organizador (modelo de cobro por torneo). Los super-admin no
// tienen límite; el resto depende de lo que se les haya autorizado en su correo.
$usuarioSesion = usuarios_obtener_por_id($usuarioId);
$limiteTorneos = usuario_limite_torneos($usuarioSesion);
$esSuper = es_superadmin($usuarioSesion);
// Copas creadas en TOTAL, no las que tiene hoy: borrar una no devuelve el cupo.
$torneosCreados = usuario_torneos_consumidos($usuarioId);
$puedeCrearTorneo = $limiteTorneos === null || $torneosCreados < $limiteTorneos;

$accion = $_GET['accion'] ?? 'lista';

// Sin cupo disponible no se abre siquiera el formulario de "Nueva copa o liga": se avisa
// desde el listado en vez de dejar llenar todo el formulario para fallar al guardar.
if ($accion === 'nuevo' && !$puedeCrearTorneo) {
    redirigir_con_mensaje(url('admin/torneos.php'), 'error', mensaje_limite_torneos($limiteTorneos));
}

// Cambiar de copa activa (no necesita CSRF: es solo un cambio de contexto, no una escritura de datos)
if ($accion === 'entrar' && isset($_GET['id'])) {
    $id = (int) $_GET['id'];
    // Se puede entrar a una copa propia o a una donde se colabora; nivel_en_copa()
    // devuelve null para cualquier otra y ahí no se cambia el contexto.
    $copa = torneos_obtener_por_id($id);
    if ($copa !== null && nivel_en_copa($copa) !== null) {
        $_SESSION['torneo_activo_id'] = $id;
    }
    header('Location: ' . url('admin/index.php'));
    exit;
}

$idEditar = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$torneoEditar = $idEditar ? torneos_obtener_por_id($idEditar, $usuarioId) : null;
$errores = [];

// Una copa cerrada o con la licencia vencida no se edita: ni se abre el formulario.
if ($accion === 'editar' && $torneoEditar !== null && !torneo_editable($torneoEditar, $esSuper)) {
    redirigir_con_mensaje(url('admin/torneos.php'), 'error', licencia_mensaje_solo_lectura($torneoEditar));
}

// Hasta dónde puede llegar la fecha de fin: la licencia de la copa que se edita o, si es
// nueva, la que se le va a dar al crearla. El super-admin no tiene tope.
$licenciaFormulario = $esSuper
    ? null
    : ($torneoEditar !== null ? ($torneoEditar['licencia_hasta'] ?? null) : licencia_hasta_por_defecto());

function torneos_slugificar(string $texto): string
{
    $texto = mb_strtolower(trim($texto), 'UTF-8');
    $mapa = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u'];
    $texto = strtr($texto, $mapa);
    $texto = preg_replace('/[^a-z0-9]+/', '-', $texto) ?? '';
    return trim($texto, '-');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'guardar') {
    csrf_validar();

    $id = (int) ($_POST['id'] ?? 0);
    // Si el id no es 0 debe pertenecer a este usuario, si no cualquiera podría guardar
    // cambios sobre una copa ajena con solo adivinar/probar su id.
    if ($id > 0 && torneos_obtener_por_id($id, $usuarioId) === null) {
        http_response_code(403);
        exit('No tienes permiso para editar esta copa o liga.');
    }
    // Segunda barrera del cupo: el bloqueo de arriba solo cubre abrir el formulario, así
    // que un POST directo (o una pestaña abierta desde antes de que se agotara el cupo)
    // todavía podría crear una copa de más. Editar una existente no consume cupo nuevo.
    if ($id === 0 && !$puedeCrearTorneo) {
        redirigir_con_mensaje(url('admin/torneos.php'), 'error', mensaje_limite_torneos($limiteTorneos));
    }
    // Candado de la licencia también aquí: esta pantalla no pasa por
    // admin_requerir_torneo_activo(), y un POST directo podría editar una copa cerrada.
    // La copa se busca por el id del POST, no por $torneoEditar: ese sale del ?id= de la
    // URL, y un POST sin él se saltaba el candado.
    $copaGuardar = $id > 0 ? torneos_obtener_por_id($id, $usuarioId) : null;
    if ($copaGuardar !== null && !torneo_editable($copaGuardar, $esSuper)) {
        redirigir_con_mensaje(url('admin/torneos.php'), 'error', licencia_mensaje_solo_lectura($copaGuardar));
    }
    // El tope de la fecha de fin, igual: el de la copa que de verdad se guarda.
    if ($copaGuardar !== null) {
        $licenciaFormulario = $esSuper ? null : ($copaGuardar['licencia_hasta'] ?? null);
    }
    $nombre = trim((string) $_POST['nombre']);
    $slug = torneos_slugificar((string) ($_POST['slug'] ?: $nombre));
    $deporteEnviado = (string) ($_POST['deporte'] ?? '');
    $deporte = isset(DEPORTES[$deporteEnviado]) ? $deporteEnviado : 'basketball';
    $genero = in_array($_POST['genero'] ?? '', ['femenino', 'masculino'], true) ? $_POST['genero'] : 'mixto';

    // Modalidad válida para el deporte elegido (fútbol 11/7/5 o basketball FIBA/NBA);
    // si no coincide, se usa la modalidad por defecto del deporte. La duración
    // personalizada es opcional (vacía = usar la reglamentaria de la modalidad).
    $modalidad = (string) ($_POST['modalidad'] ?? '');
    if (!isset(MODALIDADES_POR_DEPORTE[$deporte][$modalidad])) {
        $modalidad = MODALIDAD_POR_DEFECTO[$deporte];
    }
    // El voleibol no se juega contra reloj: no lleva minutos por periodo.
    $duracionPeriodo = deporte_usa_cronometro($deporte) && ($_POST['duracion_periodo_min'] ?? '') !== ''
        ? max(1, min(90, (int) $_POST['duracion_periodo_min']))
        : null;
    // Vueltas de la temporada regular: solo ida (1) o ida y vuelta (2).
    $vueltas = ((int) ($_POST['vueltas'] ?? 1)) === 2 ? 2 : 1;

    // Formato de la competencia: una LIGA solo lleva el control de puntos (tabla de
    // posiciones), así que se guarda sin ninguna fase de eliminación directa aunque el
    // formulario venga con casillas marcadas de cuando era campeonato.
    $modoEnviado = (string) ($_POST['modo'] ?? FORMATO_CAMPEONATO);
    $modo = array_key_exists($modoEnviado, FORMATOS_TORNEO_LABEL) ? $modoEnviado : FORMATO_CAMPEONATO;
    $fasesElegidas = $modo === FORMATO_LIGA
        ? []
        : array_values(array_intersect((array) ($_POST['fases_playoff'] ?? []), FASES_PLAYOFF_CATALOGO));

    // Los grupos solo tienen sentido en el formato de grupos: si la competencia es liga o
    // liga con fase final se guardan en 0 aunque el formulario traiga valores de antes.
    $numGrupos = $modo === FORMATO_GRUPOS ? max(2, min(26, (int) ($_POST['num_grupos'] ?? 4))) : 0;
    $clasificanPorGrupo = $modo === FORMATO_GRUPOS ? max(1, min(8, (int) ($_POST['clasifican_por_grupo'] ?? 2))) : 2;

    // No se bloquea el guardado: el organizador puede estar armando la copa por partes y
    // todavía no tener claro cuántos pasan. Se avisa y ya.
    $avisoCuadro = $modo === FORMATO_GRUPOS ? grupos_aviso_cuadro($numGrupos, $clasificanPorGrupo) : '';

    if ($nombre === '') {
        $errores[] = 'El nombre de la copa o liga es obligatorio.';
    }
    // La fecha de fin la pone el organizador, pero no puede pasar de su licencia.
    $errorFechaFin = licencia_validar_fecha_fin((string) ($_POST['fecha_fin'] ?? ''), $licenciaFormulario);
    if ($errorFechaFin !== '') {
        $errores[] = $errorFechaFin;
    }
    if ($slug === '') {
        $errores[] = 'La URL (slug) no puede quedar vacía. Usa letras, números y guiones.';
    } else {
        $existente = torneos_obtener_por_slug($slug);
        if ($existente && $existente['id'] !== $id) {
            $errores[] = "Ya existe otra copa o liga con la URL \"{$slug}\". Elige una distinta.";
        }
    }

    if (empty($errores)) {
        // ¿El escudo nuevo trae fondo? Se revisa antes de guardarlo (sobre el archivo tal
        // como lo subió) para avisarle al organizador que lo suba sin fondo.
        $logoConFondo = false;
        if (!empty($_FILES['logo']['tmp_name']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
            $logoConFondo = imagen_tiene_fondo((string) @file_get_contents($_FILES['logo']['tmp_name'])) === true;
        }
        try {
            $logoSubido = manejar_subida_imagen('logo');
            $reglamentoSubido = manejar_subida_pdf('reglamento');
        } catch (RuntimeException $e) {
            redirigir_con_mensaje(url('admin/torneos.php' . ($id ? "?accion=editar&id={$id}" : '?accion=nuevo')), 'error', $e->getMessage());
        }

        // Reglamento y logo: se sube uno nuevo, se conserva el actual, o se quita con su
        // casilla. Al reemplazarlo o quitarlo se borra el archivo viejo de la base.
        $reglamentoActual = (string) ($torneoEditar['reglamento'] ?? '');
        $reglamentoFinal = resolver_archivo_guardado($reglamentoSubido, $reglamentoActual, !empty($_POST['quitar_reglamento']));
        $reglamentoNombreFinal = match (true) {
            $reglamentoFinal === '' => '',
            $reglamentoSubido !== null => mb_substr(basename((string) ($_FILES['reglamento']['name'] ?? 'reglamento.pdf')), 0, 120),
            default => (string) ($torneoEditar['reglamento_nombre'] ?? ''),
        };

        $logoFinal = resolver_archivo_guardado($logoSubido, (string) ($torneoEditar['logo'] ?? ''), !empty($_POST['quitar_logo']));

        $datos = [
            'id' => $id ?: null,
            'slug' => $slug,
            'nombre' => $nombre,
            'subtitulo' => trim((string) $_POST['subtitulo']),
            'temporada' => trim((string) $_POST['temporada']),
            'descripcion' => trim((string) $_POST['descripcion']),
            'sede_principal' => trim((string) $_POST['sede_principal']),
            'logo' => $logoFinal,
            'reglamento' => $reglamentoFinal,
            'reglamento_nombre' => $reglamentoNombreFinal,
            'color_primario' => (string) $_POST['color_primario'],
            'color_secundario' => (string) $_POST['color_secundario'],
            'color_acento' => (string) $_POST['color_acento'],
            'fecha_inicio' => (string) $_POST['fecha_inicio'],
            'fecha_fin' => (string) $_POST['fecha_fin'],
            'formato' => trim((string) $_POST['formato']),
            'instagram' => trim((string) $_POST['instagram']),
            'facebook' => trim((string) ($_POST['facebook'] ?? '')),
            'tiktok' => trim((string) ($_POST['tiktok'] ?? '')),
            // WhatsApp se guarda tal como lo escribió el organizador (número o enlace) y
            // se convierte a enlace al mostrarlo, con url_whatsapp().
            'whatsapp' => trim((string) ($_POST['whatsapp'] ?? '')),
            'hero_frase' => trim((string) $_POST['hero_frase']),
            'deporte' => $deporte,
            'genero' => $genero,
            'modo' => $modo,
            'vueltas' => $vueltas,
            'modalidad' => $modalidad,
            'duracion_periodo_min' => $duracionPeriodo,
            'num_equipos' => max(2, (int) $_POST['num_equipos']),
            'fases_playoff' => $fasesElegidas,
            'num_grupos' => $numGrupos,
            'clasifican_por_grupo' => $clasificanPorGrupo,
            // El podio se publica desde el dashboard, no desde este formulario: se
            // conserva tal cual estaba para que editar la copa no lo baje sin querer.
            'podio_publicado' => !empty($torneoEditar['podio_publicado']),
            // En voleibol no existe el empate (alguien siempre gana los sets).
            'permite_empates' => !es_voleibol($deporte) && isset($_POST['permite_empates']),
            'puntos_victoria' => (int) $_POST['puntos_victoria'],
            'puntos_empate' => (int) $_POST['puntos_empate'],
            'puntos_derrota' => (int) $_POST['puntos_derrota'],
            // Disciplina: tarifas por tarjeta y reglas de bloqueo. Se aceptan decimales
            // (hay ligas que cobran 12.50) y nunca negativos.
            'multa_amarilla' => max(0, (float) ($_POST['multa_amarilla'] ?? 0)),
            'multa_roja' => max(0, (float) ($_POST['multa_roja'] ?? 0)),
            'sancion_bloquea' => isset($_POST['sancion_bloquea']),
            'partidos_suspension_roja' => max(0, min(10, (int) ($_POST['partidos_suspension_roja'] ?? 0))),
            'amarillas_para_suspension' => max(0, min(20, (int) ($_POST['amarillas_para_suspension'] ?? 0))),
            'partidos_suspension_amarillas' => max(0, min(10, (int) ($_POST['partidos_suspension_amarillas'] ?? 1))),
            'moneda' => trim((string) ($_POST['moneda'] ?? 'Q')) !== '' ? mb_substr(trim((string) $_POST['moneda']), 0, 4) : 'Q',
            // Cuotas de la liga: en cero no se cobra y la pantalla de Cuentas no ofrece
            // generar ese cargo. Cada liga pone las suyas.
            // Congelar las plantillas de los capitanes. Nunca afecta al dueño.
            'inscripciones_abiertas' => isset($_POST['inscripciones_abiertas']),
            'cuota_inscripcion' => max(0, (float) ($_POST['cuota_inscripcion'] ?? 0)),
            'cuota_arbitraje' => max(0, (float) ($_POST['cuota_arbitraje'] ?? 0)),
            'multas_al_equipo' => isset($_POST['multas_al_equipo']),
            'es_predeterminado' => !empty($torneoEditar['es_predeterminado']),
            'activo' => true,
        ];

        $idGuardado = torneos_guardar($datos, $usuarioId);
        bitacora_registrar($id ? 'torneo_editado' : 'torneo_creado', '"' . $nombre . '" (' . $deporte . ')', $idGuardado);

        // Copa nueva: consume un cupo para siempre y arranca su licencia. Las del
        // super-admin no llevan licencia (son demos y soporte).
        if ($id === 0) {
            usuario_sumar_torneo_creado($usuarioId);
            if (!$esSuper) {
                torneos_fijar_licencia($idGuardado, $licenciaFormulario);
            }
        }

        if (empty($_SESSION['torneo_activo_id'])) {
            $_SESSION['torneo_activo_id'] = $idGuardado;
        }

        $mensaje = $id ? 'Copa o liga actualizada correctamente.' : '¡Copa o liga creada! Ya puedes cargar sus equipos y encuentros.';
        // Se guarda igual, pero se le pide que lo cambie por uno sin fondo.
        if ($logoConFondo) {
            redirigir_con_mensaje(url('admin/torneos.php?accion=editar&id=' . $idGuardado), 'warning', $mensaje . ' Ojo: ' . MENSAJE_ESCUDO_SIN_FONDO);
        }
        if (!empty($avisoCuadro)) {
            redirigir_con_mensaje(url('admin/torneos.php'), 'error', $mensaje . ' Ojo: ' . $avisoCuadro);
        }
        redirigir_con_mensaje(url('admin/torneos.php'), 'success', $mensaje);
    } else {
        $torneoEditar = array_merge($_POST, ['id' => $id, 'fases_playoff' => $fasesElegidas, 'modo' => $modo, 'vueltas' => $vueltas]);
        $accion = $id ? 'editar' : 'nuevo';
    }
}

// Copa de demostración de voleibol (datos inventados), para enseñar la plataforma igual
// que la Copa Estrellas en basketball. Solo los super-admin: crea una copa completa de un
// clic y no debe estar al alcance de un organizador con cupo.
if (($_SERVER['REQUEST_METHOD'] === 'POST') && ($_POST['accion'] ?? '') === 'crear_demo_voleibol') {
    csrf_validar();
    if (!es_superadmin($usuarioSesion)) {
        http_response_code(403);
        exit('Solo un super-admin puede crear la copa de demostración.');
    }
    $demoId = demo_crear_copa_voleibol($usuarioId);
    bitacora_registrar('torneo_creado', '"Copa Voleibol Demo" (voleibol, datos de demostración)', $demoId);
    $_SESSION['torneo_activo_id'] = $demoId;
    redirigir_con_mensaje(url('admin/torneos.php'), 'success', 'Copa Voleibol Demo creada con 8 equipos, plantillas y seis jornadas jugadas. Ya es tu copa activa.');
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && ($_POST['accion'] ?? '') === 'eliminar') {
    csrf_validar();
    $id = (int) $_POST['id'];
    try {
        $torneoBorrar = torneos_obtener_por_id($id, $usuarioId);
        torneos_eliminar($id, $usuarioId);
        bitacora_registrar('torneo_eliminado', '"' . ($torneoBorrar['nombre'] ?? "#{$id}") . '" eliminada con todos sus datos');
        if (($_SESSION['torneo_activo_id'] ?? null) === $id) {
            unset($_SESSION['torneo_activo_id']);
        }
        redirigir_con_mensaje(url('admin/torneos.php'), 'success', 'Copa o liga eliminada.');
    } catch (RuntimeException $e) {
        redirigir_con_mensaje(url('admin/torneos.php'), 'error', $e->getMessage());
    }
}

// --- Licencia: solo el super-admin ---
//
// Mover la fecha de la licencia (vender más tiempo, o dejarla sin límite con el campo
// vacío) y reabrir un campeonato cerrado. Se busca la copa sin filtrar por dueño porque
// casi siempre es de otro organizador.
if (($_SERVER['REQUEST_METHOD'] === 'POST') && in_array($_POST['accion'] ?? '', ['fijar_licencia', 'reabrir_campeonato'], true)) {
    csrf_validar();
    if (!$esSuper) {
        http_response_code(403);
        exit('Solo un super-admin puede cambiar la licencia de una copa.');
    }
    $copaLicencia = torneos_obtener_por_id((int) ($_POST['id'] ?? 0));
    if ($copaLicencia === null) {
        redirigir_con_mensaje(url('admin/torneos.php'), 'error', 'Copa o liga no encontrada.');
    }

    if ($_POST['accion'] === 'reabrir_campeonato') {
        torneos_reabrir((int) $copaLicencia['id']);
        bitacora_registrar('campeonato_reabierto', '"' . $copaLicencia['nombre'] . '" vuelve a ser editable', (int) $copaLicencia['id']);
        redirigir_con_mensaje(url('admin/torneos.php'), 'success', 'Campeonato reabierto: "' . $copaLicencia['nombre'] . '" se puede editar de nuevo.');
    }

    $fecha = trim((string) ($_POST['licencia_hasta'] ?? ''));
    if ($fecha !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        redirigir_con_mensaje(url('admin/torneos.php'), 'error', 'Fecha de licencia no válida.');
    }
    torneos_fijar_licencia((int) $copaLicencia['id'], $fecha !== '' ? $fecha : null);
    bitacora_registrar('licencia_actualizada', '"' . $copaLicencia['nombre'] . '": ' . ($fecha !== '' ? 'hasta el ' . licencia_fecha_legible($fecha) : 'sin límite'), (int) $copaLicencia['id']);
    redirigir_con_mensaje(url('admin/torneos.php'), 'success', 'Licencia de "' . $copaLicencia['nombre'] . '" actualizada.');
}

if (($_SERVER['REQUEST_METHOD'] === 'POST') && ($_POST['accion'] ?? '') === 'regenerar_codigo') {
    csrf_validar();
    $id = (int) $_POST['id'];
    try {
        torneos_regenerar_codigo($id, $usuarioId);
        bitacora_registrar('codigo_regenerado', 'Código de acceso regenerado', $id);
        redirigir_con_mensaje(url('admin/torneos.php'), 'success', 'Código regenerado.');
    } catch (RuntimeException $e) {
        redirigir_con_mensaje(url('admin/torneos.php'), 'error', $e->getMessage());
    }
}

// ¿El escudo que ya tiene la copa trae fondo? Se le avisa en el formulario hasta que lo cambie.
$escudoConFondo = !empty($torneoEditar['logo']) && imagen_tiene_fondo((string) imagen_datos((string) $torneoEditar['logo'])) === true;

// Una copa nueva creada desde la card de un deporte en "Mis copas" ya trae ese deporte.
$deportePorDefecto = $torneoEditar['deporte'] ?? (isset(DEPORTES[$_GET['deporte'] ?? '']) ? (string) $_GET['deporte'] : 'basketball');
$generoPorDefecto = $torneoEditar['genero'] ?? 'mixto';
// Las copas creadas antes de que existiera el selector de formato quedan como campeonato
// (mismo comportamiento que tenían), no como liga.
$modoPorDefecto = ($torneoEditar['modo'] ?? FORMATO_CAMPEONATO) === FORMATO_LIGA ? FORMATO_LIGA : FORMATO_CAMPEONATO;
$vueltasPorDefecto = torneo_vueltas($torneoEditar ?? []);
// Además de las propias se listan aquellas donde a esta persona la invitaron a ayudar.
$usuarioActual = usuarios_obtener_por_id($usuarioId);
$torneos = torneos_accesibles($usuarioId, (string) ($usuarioActual['email'] ?? ''));

$seccion_activa = 'torneos';
$titulo_pagina = 'Mis Copas y Ligas';

vista_admin('admin/torneos', compact(
    'usuarioSesion',
    'accion',
    'deportePorDefecto',
    'errores',
    'escudoConFondo',
    'generoPorDefecto',
    'licenciaFormulario',
    'limiteTorneos',
    'modoPorDefecto',
    'puedeCrearTorneo',
    'seccion_activa',
    'titulo_pagina',
    'torneoEditar',
    'torneos',
    'torneosCreados',
    'vueltasPorDefecto'
));
