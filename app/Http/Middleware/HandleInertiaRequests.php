<?php

namespace App\Http\Middleware;

use App\Models\CooperativeDriverMembership;
use App\Models\FleetInvitation;
use App\Models\RideRequest;
use App\Models\SiteSetting;
use App\Services\PlanLimits;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    public function __construct(private readonly PlanLimits $planLimits) {}

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $siteSetting = SiteSetting::current();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user,
                // Cada cuenta es cliente O conductor, nunca las dos (ver
                // App\Models\User::isClient()/isDriver() y los chequeos de
                // exclusividad en FleetController/DriverProfileController).
                'isDriver' => $user?->isDriver() ?? false,
                'isClient' => $user?->isClient() ?? false,
                'isCooperative' => $user?->isCooperative() ?? false,
                'hasFleet' => $user ? $user->fleets()->exists() : false,
                // Para la insignia compacta "★4.5" del propio usuario en el
                // menú de cuenta (sección 3.6) — mismo criterio que el perfil
                // público (App\Http\Controllers\PublicProfileController).
                'averageRating' => $user ? round((float) $user->reviewsReceived()->avg('rating'), 1) : 0,
                'reviewCount' => $user ? $user->reviewsReceived()->count() : 0,
                // Plan vigente del rol activo, para mostrarlo debajo de la
                // calificación en el menú de cuenta (consideración agregada al
                // alcance).
                'plans' => $user ? [
                    'driver' => $user->isDriver() ? $this->planLimits->forDriver($user)['plan_name'] : null,
                    'client' => $user->isClient() ? $this->planLimits->forClient($user)['plan_name'] : null,
                    'cooperative' => $user->isCooperative() ? $this->planLimits->forCooperative($user)['plan_name'] : null,
                ] : null,
                // Pedido explícito del usuario: "que el conductor sepa
                // también en cuál está" — la insignia de verificado depende
                // de la aprobación del admin Y de que el plan la incluya
                // (mismo criterio que RideRequestController::driverCardData()
                // y DriverDirectoryController), para que se vea igual acá que
                // donde la ven los clientes.
                'hasVerifiedBadge' => $user?->isDriver()
                    ? $user->driverProfile?->verification_status === 'approved'
                        && $this->planLimits->forDriver($user)['verified_badge']
                    : null,
                // Pedido explícito del usuario ("un puntitto rojo con un
                // uno para que vaya y actualice"), acotado al perfil del
                // CLIENTE (lo que se pidió) — un conductor/admin/cooperativa
                // no ven este aviso, sus pantallas de perfil son otras y no
                // se tocaron. Compartido acá (no solo en Profile/Edit.vue)
                // porque el punto de atención tiene que verse en la nav de
                // CUALQUIER pantalla — mismo criterio que el resto de
                // auth.* de acá arriba. Sin queries extra: son columnas que
                // el modelo ya trae cargadas en cada request autenticado.
                'isProfileIncomplete' => $user && $user->isClient()
                    ? blank($user->last_name)
                        || blank($user->city_id)
                        || blank($user->phone)
                        || ! $user->phone_verified_at
                    : false,
                // Pedido explícito del usuario: "coloca solicitudes en el
                // navbar... alli donde esta el boton de home" — el badge de
                // la pestaña "Carreras" de la nav inferior necesita este
                // número en CUALQUIER pantalla, no solo en Inicio, así que
                // vive acá y no en DashboardController. Ver
                // RideRequest::pendingIncomingFor() — mismo criterio (algo
                // más simple que el de /carreras) usado también ahí.
                'pendingRideRequestsCount' => $user?->isDriver()
                    ? RideRequest::pendingIncomingFor($user->id)->count()
                    : 0,
                // Pedido explícito del usuario: "quitemos carreras y
                // coloquemos clientes" — el tab "Clientes" de la nav
                // inferior (antes "Carreras", redundante con "Solicitudes")
                // necesita este número en cualquier pantalla, mismo
                // criterio que pendingRideRequestsCount de arriba.
                'pendingFleetInvitationsCount' => $user?->isDriver()
                    ? FleetInvitation::query()->where('driver_user_id', $user->id)->where('status', 'pending')->count()
                    : 0,
                // Pedido explícito del usuario: "eso es para que el sepa
                // que pertenece a una cooperativa, colocalo alli [menú de
                // cuenta] como una etiqueta mas con su enlace... debajo de
                // la que dice conductor" — antes solo vivía en Inicio
                // (Dashboard.vue), ahora en el menú de cuenta se ve en
                // cualquier pantalla.
                'cooperative' => $user?->isDriver()
                    ? CooperativeDriverMembership::activeCooperativeFor($user->id)?->only(['id', 'name'])
                    : null,
            ],
            // Pedido explícito del usuario ("permiteme en el modulo de
            // sistema de habilitar o no estas opciones del menu"): rutas de
            // accesos rápidos que un admin apagó — AuthenticatedLayout.vue
            // filtra su `quickLinks` con esto. Vacío por defecto: nadie
            // pierde ningún acceso de golpe con esto recién agregado.
            'disabledQuickLinks' => $siteSetting->disabled_quick_links ?? [],
            // Pedido explícito del usuario ("una lista de sonidos que pueda
            // seleccionar para las notificaciones... y que tenga todo el
            // volumen"): qué sonido eligió el admin para cada categoría de
            // aviso, más el volumen maestro — Utils/liveAlert.js los lee acá
            // para sintetizar cada aviso. Vacío/100 por defecto: nadie
            // pierde sonido ni volumen de golpe con esto recién agregado.
            'notificationSounds' => $siteSetting->notification_sounds ?? [],
            'notificationVolume' => $siteSetting->notification_volume ?? 100,
            // Notificaciones push (sección 9.2 y 9.5): el frontend la necesita
            // para suscribirse vía PushManager, nunca la llave privada.
            'vapidPublicKey' => config('webpush.vapid.public_key'),
            // El botón "Continuar con Google" del login solo se muestra si el
            // usuario ya completó las credenciales de OAuth en .env — así no
            // hay un botón roto mientras tanto.
            'googleLoginEnabled' => filled(config('services.google.client_id')) && filled(config('services.google.client_secret')),
            // Fondo del panel de marca en login/registro (pedido explícito
            // del usuario, configurable desde /admin/sitio) — compartido acá
            // en vez de repetirlo en cada controlador de sesión/registro
            // porque AuthBrandingPanel.vue vive dentro de GuestLayout.vue,
            // usado por todos ellos por igual.
            'authBackgroundUrl' => $siteSetting->auth_background_url,
            // Mensajes flash tipo ->with('status', '...'), compartidos acá
            // porque antes cada página tenía que pasarlos a mano (y casi
            // ninguna lo hacía) — ver banner en AuthenticatedLayout.vue.
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
        ];
    }
}
