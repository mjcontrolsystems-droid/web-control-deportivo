<?php
declare(strict_types=1);

// Página de error 404 con la marca del sitio (Apache la sirve vía ErrorDocument, ver
// apache-vhost.conf). Un error con diseño transmite cuidado; un texto plano, abandono.
http_response_code(404);

// Un robot buscando /wp-admin, /.env o phpMyAdmin no es un enlace roto: es alguien
// tanteando la plataforma. Se registra (agrupado por IP) en el panel de Seguridad.
if (seguridad_es_escaneo((string) ($_SERVER['REQUEST_URI'] ?? ''))) {
    seguridad_registrar('escaneo', 'Ruta típica de ataque');
}

vista('publico/no_encontrado');
