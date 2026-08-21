<?php

return [

    'google_maps' => [
        // Clave de servidor para Routes API. Debe restringirse por IP del
        // servidor; nunca se envía al navegador.
        'server_api_key' => env('GOOGLE_MAPS_SERVER_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Login con Google (Socialite) — sección "Iniciar sesión con Gmail".
    // Credenciales de un proyecto OAuth en Google Cloud Console, las tiene
    // que completar el usuario en .env (nunca hardcodeadas acá).
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Verificación de teléfono por WhatsApp (Meta WhatsApp Cloud API) —
    // consideración de seguridad agregada al alcance. Credenciales de Meta
    // Business Manager, las completa el usuario en .env; si quedan vacías,
    // el registro sigue funcionando sin exigir verificación (ver
    // App\Services\WhatsAppVerificationSender).
    'whatsapp' => [
        'token' => env('WHATSAPP_TOKEN'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'verification_template' => env('WHATSAPP_VERIFICATION_TEMPLATE', 'arka01_verificacion'),
        // Ventana de 24h de mensajes libres (pedido explícito del usuario):
        // el número tal cual lo marca la gente (ej. "593991234567", sin "+")
        // para armar el link "wa.me/<numero>?text=Hola" — distinto de
        // phone_number_id, que es el ID interno de Meta para mandar por la API.
        'business_number' => env('WHATSAPP_BUSINESS_NUMBER'),
        // Secreto que vos elegís y pegás también en Meta for Developers al
        // configurar el webhook — confirma que las llamadas al webhook son
        // de verdad de Meta.
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        // App Secret de Meta (distinto del token de arriba): firma cada POST
        // entrante con HMAC-SHA256 en el header X-Hub-Signature-256 — sin
        // esto, cualquiera que conozca la URL puede simular un mensaje real
        // (ver App\Http\Middleware\VerifyWhatsAppSignature).
        'app_secret' => env('WHATSAPP_APP_SECRET'),
    ],

];
