<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#dfe9e4">

        {{-- Aplica la preferencia antes de cargar Vue para evitar un destello claro.
             La clave incluye el usuario: dos cuentas en el mismo equipo conservan
             su propio ambiente visual. --}}
        <script>
            (() => {
                const accountId = @json(data_get($page, 'props.auth.user.id', 'guest'));
                const storageKey = `arka_theme_${accountId ?? 'guest'}`;
                let theme = 'light';

                try {
                    theme = window.localStorage.getItem(storageKey) === 'dark' ? 'dark' : 'light';
                } catch (error) {
                    // Si el navegador bloquea el almacenamiento, se mantiene el tema claro.
                }

                document.documentElement.classList.toggle('dark', theme === 'dark');
                document.documentElement.dataset.theme = theme;
                window.__ARKA_THEME_KEY__ = storageKey;
            })();
        </script>

        {{-- PWA (sección 9.2 y 9.9): instalable a pantalla completa, sin la barra del navegador --}}
        <link rel="manifest" href="/manifest.json">
        <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/icons/icon.svg">
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="default">
        <meta name="apple-mobile-web-app-title" content="Arka01">
        <meta name="mobile-web-app-capable" content="yes">

        <title inertia>{{ config('app.name', 'Arka01') }}</title>

        {{-- Sin fuentes externas a propósito: la fuente de sistema carga más rápido y se siente nativa (sección 9.9) --}}

        {{-- En la portada, estas imágenes forman parte del primer contenido visible.
             Precargarlas desde el HTML inicial evita que Vue aparezca antes que la
             fotografía y deje visible durante unos instantes el fondo provisional. --}}
        @if (($page['component'] ?? null) === 'Welcome')
            <link rel="preload" as="image" href="/img/home/imagen%20para%20movil%20inicio.png" media="(max-width: 767px)" fetchpriority="high">
            <link rel="preload" as="image" href="/img/home/imagen%20para%20escritorio%20inicio%20de%20arka01.png" media="(min-width: 768px)" fetchpriority="high">
        @endif

        <!-- Scripts -->
        @routes
        @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-arka-base text-arka-text">
        @inertia
    </body>
</html>
