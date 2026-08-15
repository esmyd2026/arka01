<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['GET', 'POST', 'OPTIONS'],

    // Auditoría de seguridad: estaba en '*' (cualquier sitio podía llamar a
    // /api/* con el navegador de un usuario logueado). El riesgo real acá era
    // más bajo de lo que suena — /api/user exige un token de Sanctum (no hay
    // auth por cookie activada, EnsureFrontendRequestsAreStateful sigue
    // comentado en app/Http/Kernel.php) y el webhook de WhatsApp valida su
    // propia firma HMAC sin importar el origen — pero un '*' abierto en CORS
    // no tiene ninguna razón de ser en esta app: no es una API pública
    // consumida desde otros dominios. Se restringe al propio dominio
    // (APP_URL) y, en local, a los puertos típicos de Vite/HMR.
    'allowed_origins' => array_values(array_filter([
        env('APP_URL'),
        env('APP_ENV') === 'local' ? 'http://localhost:5173' : null,
        env('APP_ENV') === 'local' ? 'http://127.0.0.1:5173' : null,
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Content-Type', 'X-Requested-With', 'X-CSRF-TOKEN', 'X-Inertia'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
