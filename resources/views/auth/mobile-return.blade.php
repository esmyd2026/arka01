<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Volviendo a Arka01…</title>
    <style>
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1.25rem;
            background: #F4F7F5;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #17211D;
            text-align: center;
            padding: 1.5rem;
        }
        .spinner {
            width: 2.25rem;
            height: 2.25rem;
            border: 3px solid rgba(23, 139,98, .25);
            border-top-color: #178B62;
            border-radius: 999px;
            animation: spin .8s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }
        a.fallback {
            display: none;
            margin-top: .5rem;
            padding: .7rem 1.4rem;
            border-radius: .8rem;
            background: #178B62;
            color: #fff;
            font-weight: 700;
            font-size: .9rem;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="spinner" aria-hidden="true"></div>
    <p>Volviendo a la app…</p>
    {{-- Bug real reportado por el usuario ("la app se queda cargando... nunca
         entra"): antes esto era una redirección HTTP directa (Location:
         com.arka01.app://...) a un esquema personalizado. Android/Chrome
         Custom Tabs puede negarse a entregarle el control a la app cuando esa
         navegación llega como una redirección del servidor en vez de como una
         acción disparada DESDE la propia página — sin ningún aviso, se queda
         mostrando esta pestaña quieta. Una navegación por JavaScript, ya
         adentro de la página, es el patrón que de verdad funciona para volver
         a la app en este escenario. Si por lo que sea el navegador la bloquea
         igual, el botón de abajo (que sí es un toque real de la persona)
         alcanza para volver — nunca deja a alguien sin ninguna salida. --}}
    <a id="fallback" class="fallback" href="{{ $url }}">Toque acá para volver a la app</a>

    <script>
        window.location.href = @json($url);
        setTimeout(function () {
            document.getElementById('fallback').style.display = 'inline-block';
        }, 1500);
    </script>
</body>
</html>
