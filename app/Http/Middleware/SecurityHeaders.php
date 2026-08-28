<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rendimiento/seguridad de cara a producción (pedido explícito del usuario):
 * ninguna respuesta traía headers de seguridad — cualquiera podía embeber la
 * app en un iframe ajeno (clickjacking) y no había ninguna política de qué
 * orígenes pueden cargar scripts/imágenes/conexiones.
 *
 * El CSP no es "todo 'self'" a propósito: Ziggy (@routes) imprime un
 * <script> inline en cada página (de ahí 'unsafe-inline' en script-src — una
 * versión más estricta necesitaría migrar a nonces, fuera de este alcance),
 * y la app de verdad carga recursos de estos orígenes externos:
 * - OpenStreetMap (mapa base, Components/FleetMap.vue)
 * - OSRM (trazado de ruta, Pages/Ride/Request.vue)
 * - Nominatim/OSM (geocodificación inversa para "usar mi ubicación actual",
 *   Pages/Ride/Request.vue — gratis, sin key, mismo criterio que OSRM)
 * - Google Maps JS/Places (autocompletado de direcciones, Utils/googleMaps.js,
 *   opcional según VITE_GOOGLE_MAPS_API_KEY)
 * - Reverb (WebSocket en vivo, wss/ws según entorno)
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        // Estos endpoints sirven documentos privados únicamente al dueño o
        // a un administrador. El panel admin los muestra en un iframe del
        // MISMO origen; aplicar DENY/frame-ancestors none también ahí hacía
        // que el archivo existiera pero Chrome mostrara un visor vacío.
        $isPrivateDriverDocument = $request->routeIs([
            'driver-profile.document',
            'driver-profile.license-photo',
        ]);

        // El CSP NO se aplica en local (pedido explícito del usuario, fix de
        // un bug real: pantalla en blanco). El servidor de desarrollo de Vite
        // sirve los scripts del hot-reload desde otro origen/puerto (5173),
        // y Chrome directamente descarta como "origen inválido" cualquier
        // fuente CSP con el literal IPv6 `[::1]` que Vite eligió acá —
        // ninguna lista de orígenes explícitos alcanza a cubrirlo de forma
        // confiable. El CSP es una protección pensada para producción (mismo
        // origen sirviendo todo, sin dev server); en local, la única persona
        // navegando es quien está programando en su propia máquina, así que
        // no hay una amenaza real que mitigar acá.
        if (! app()->environment('local')) {
            // WalkieTalkie.vue carga `${VITE_RADIO_URL}/socket.io/socket.io.js`
            // con un <script src> de verdad (no un import empaquetado), porque
            // así siempre coincide con la versión del cliente que sirve
            // radio-server. Ese origen es distinto del propio (arka01.com:3000
            // en producción) y necesita estar en script-src o Chrome bloquea la
            // carga (bug real: "No se pudo cargar el servicio de radio.").
            $radioOrigin = rtrim((string) config('radio.url'), '/');

            $csp = implode('; ', [
                "default-src 'self'",
                "script-src 'self' 'unsafe-inline' https://maps.googleapis.com".($radioOrigin !== '' ? " {$radioOrigin}" : ''),
                "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",
                "font-src 'self' data: https://fonts.gstatic.com",
                // FleetMap usa CARTO Positron/Dark Matter. Sus teselas llegan
                // desde subdominios a-d.basemaps.cartocdn.com; si este origen
                // no figura aquí, el mapa funciona en local (sin CSP) pero
                // aparece vacío en producción.
                // Google OAuth entrega los avatares de cuenta desde lh3.
                // Autorizar el host exacto evita abrir todos los subdominios
                // de googleusercontent.com sin necesidad.
                "img-src 'self' data: blob: https://*.tile.openstreetmap.org https://*.basemaps.cartocdn.com https://maps.gstatic.com https://maps.googleapis.com https://lh3.googleusercontent.com",
                // La API "nueva" de Google Places (Utils/googleMaps.js) manda las
                // llamadas de autocompletado por gRPC-Web a places.googleapis.com
                // — un origen DISTINTO del que usa el resto de Maps
                // (maps.googleapis.com, ya permitido arriba). Bug real reportado
                // por el usuario: sin este origen, el CSP bloqueaba la conexión
                // en silencio (el script cargaba bien, la sugerencia nunca volvía).
                "connect-src 'self' ws: wss: https://router.project-osrm.org https://nominatim.openstreetmap.org https://maps.googleapis.com https://places.googleapis.com",
                "worker-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                $isPrivateDriverDocument ? "frame-ancestors 'self'" : "frame-ancestors 'none'",
            ]);

            $response->headers->set('Content-Security-Policy', $csp);
        }

        $response->headers->set('X-Frame-Options', $isPrivateDriverDocument ? 'SAMEORIGIN' : 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        // La radio privada necesita capturar audio desde páginas de Arka01.
        // Se mantiene limitado al mismo origen: ningún iframe o sitio externo
        // puede solicitar el micrófono usando nuestra política.
        $response->headers->set('Permissions-Policy', 'geolocation=(self), camera=(), microphone=(self)');

        // HSTS solo tiene sentido si de verdad se sirve por HTTPS (en local,
        // sobre HTTP, este header no hace nada dañino pero tampoco aporta).
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
