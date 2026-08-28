<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Radio web Push-to-Talk
    |--------------------------------------------------------------------------
    |
    | Laravel y el repetidor Node comparten esta clave únicamente para firmar
    | credenciales efímeras. Debe ser distinta de APP_KEY y tener al menos
    | 64 caracteres en cualquier entorno donde la radio esté habilitada.
    |
    */
    'shared_secret' => env('RADIO_SHARED_SECRET'),
    'token_ttl_seconds' => (int) env('RADIO_TOKEN_TTL_SECONDS', 1800),

    // Mismo valor que VITE_RADIO_URL (el frontend ya lo usa para armar la URL
    // del socket). Se repite acá porque el backend también lo necesita: el
    // CSP tiene que autorizar este origen en script-src, si no el navegador
    // bloquea la carga de `${VITE_RADIO_URL}/socket.io/socket.io.js`.
    'url' => env('VITE_RADIO_URL'),
];
