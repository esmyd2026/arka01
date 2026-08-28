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
];
