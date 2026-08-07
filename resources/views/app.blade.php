<!DOCTYPE html>
{{-- Arka01 usa modo oscuro fijo (clase "dark" siempre presente), según la identidad visual de la sección 9.9 del alcance --}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#0a0f0c">

        {{-- PWA (sección 9.2 y 9.9): instalable a pantalla completa, sin la barra del navegador --}}
        <link rel="manifest" href="/manifest.json">
        <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/icons/icon.svg">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="Arka01">
        <meta name="mobile-web-app-capable" content="yes">

        <title inertia>{{ config('app.name', 'Arka01') }}</title>

        {{-- Sin fuentes externas a propósito: la fuente de sistema carga más rápido y se siente nativa (sección 9.9) --}}

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-arka-base text-arka-text">
        @inertia
    </body>
</html>
