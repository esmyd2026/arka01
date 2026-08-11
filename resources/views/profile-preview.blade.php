<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>

    {{-- Pedido explícito del usuario: tarjeta de vista previa profesional al
         compartir el enlace por WhatsApp — solo la ven los rastreadores
         (ver PublicProfileController::show()), nunca una persona real. --}}
    <meta property="og:type" content="profile">
    <meta property="og:title" content="{{ $title }}">
    <meta property="og:description" content="{{ $description }}">
    <meta property="og:image" content="{{ $image }}">
    <meta property="og:url" content="{{ $url }}">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="{{ $title }}">
    <meta name="twitter:description" content="{{ $description }}">
    <meta name="twitter:image" content="{{ $image }}">

    {{-- Por si algún rastreador no reconocido llega a mostrarle esto a una
         persona real, la manda derecho a la app de verdad. --}}
    <meta http-equiv="refresh" content="0; url={{ $url }}">
</head>
<body>
    <p><a href="{{ $url }}">Ver perfil en Arka01</a></p>
</body>
</html>
