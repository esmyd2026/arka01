<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Versión mínima de la app móvil
    |--------------------------------------------------------------------------
    |
    | GET /api/v1/config la devuelve tal cual. Cuando exista una versión
    | publicada que ya no se pueda seguir sirviendo, se sube este valor y la
    | app fuerza la actualización (ROADMAP_APLICACION_MOVIL_CAPACITOR.md,
    | sección 4.3). Sin panel de administración todavía — se ajusta acá o por
    | variable de entorno hasta que haga falta uno.
    |
    */

    'min_version' => [
        'android' => env('MOBILE_MIN_VERSION_ANDROID', '1.0.0'),
        'ios' => env('MOBILE_MIN_VERSION_IOS', '1.0.0'),
    ],

    'maintenance' => env('MOBILE_MAINTENANCE', false),

    /*
    |--------------------------------------------------------------------------
    | Vencimiento del token móvil
    |--------------------------------------------------------------------------
    |
    | Cuántos días vive un token de Sanctum emitido por el login móvil antes
    | de que la app tenga que volver a autenticar. No afecta a los tokens web
    | (esta app no usa el modo SPA de Sanctum) ni al login normal por sesión.
    |
    */

    'token_ttl_days' => (int) env('MOBILE_TOKEN_TTL_DAYS', 90),

];
