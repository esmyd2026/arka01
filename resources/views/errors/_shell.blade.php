<!DOCTYPE html>
{{-- Pantalla de error amigable (pedido explícito del usuario, con captura
     del "503 | SERVICE UNAVAILABLE" en blanco y negro que muestra Laravel
     por defecto): un solo cascarón visual reutilizado por cada código
     (403/404/419/429/500/503, ver los archivos hermanos en esta carpeta),
     con el estilo oscuro de Arka01. A propósito SIN @vite ni ninguna otra
     dependencia de los assets compilados — esta es justo la pantalla que
     tiene que verse bien incluso cuando el build está a medio desplegar o
     algo más de la app está roto, así que va con CSS en línea nomás. --}}
<html lang="es" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0a0f0c">
    <link rel="icon" href="/icons/icon.svg" type="image/svg+xml">
    <title>{{ $title }} · Arka01</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
            background: #0a0f0c;
            background-image: radial-gradient(circle at 20% 20%, rgba(52, 211, 153, 0.10), transparent 45%);
            color: #eafbf3;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            text-align: center;
        }
        .card { max-width: 26rem; }
        .code {
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.15em;
            color: #34d399;
            text-transform: uppercase;
        }
        h1 { margin: 0.5rem 0 0.75rem; font-size: 1.5rem; font-weight: 700; }
        p { margin: 0; color: #9fb0a8; line-height: 1.5; }
        a.button {
            display: inline-flex;
            margin-top: 1.75rem;
            padding: 0.625rem 1.5rem;
            border-radius: 0.5rem;
            background: #34d399;
            color: #0a0f0c;
            font-weight: 600;
            font-size: 0.875rem;
            text-decoration: none;
        }
        a.button:hover { background: #6ee7b7; }
        svg.logo { height: 2.5rem; margin-bottom: 1.5rem; }
    </style>
</head>
<body>
    <div class="card">
        <svg class="logo" viewBox="0 0 24 24" fill="none" stroke="#34d399" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 8 3.5v5.2c0 4.4-3 7.6-8 9.3-5-1.7-8-4.9-8-9.3V6.5L12 3Z" />
        </svg>
        <p class="code">Error {{ $code }}</p>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        @if($showHomeLink ?? true)
            <a class="button" href="/">Volver al inicio</a>
        @endif
    </div>
</body>
</html>
