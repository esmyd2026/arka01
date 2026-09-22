<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Firebase Cloud Messaging (push nativo Android/iOS)
    |--------------------------------------------------------------------------
    |
    | Groundwork del Hito 6 (roadmap app móvil): el "timbre" de una carrera
    | nueva o su cambio de estado, por push nativo, para que llegue aunque
    | la app esté cerrada — no lo cubre WebPush (solo navegador/PWA
    | abiertos). Ver App\Services\Push\FcmSender y App\Notifications\
    | Channels\FcmChannel. Mientras estas variables no estén en .env, el
    | canal simplemente no envía nada (sin romper el resto del flujo) — ver
    | PENDIENTES_USUARIO_APP_MOVIL.md, punto 1.
    |
    */

    // Identificador del proyecto de Firebase (Configuración del proyecto →
    // General → "ID del proyecto").
    'project_id' => env('FIREBASE_PROJECT_ID'),

    // Ruta absoluta al archivo JSON de la cuenta de servicio (Configuración
    // del proyecto → Cuentas de servicio → Generar nueva clave privada).
    // Nunca se sube al repositorio — va fuera de /public, cargado a mano en
    // el servidor (mismo criterio que cualquier otra credencial del .env).
    'credentials_path' => env('FIREBASE_CREDENTIALS_PATH'),

];
