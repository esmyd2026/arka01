<?php

namespace App\Http\Middleware;

use App\Models\CooperativeDriverMembership;
use App\Models\DriverProfile;
use App\Models\FleetInvitation;
use App\Models\RideRequest;
use App\Models\SiteSetting;
use App\Models\TrustCircleConnection;
use App\Models\User;
use App\Services\Driver\DriverAccessResolver;
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

    public function __construct(
        private readonly PlanLimits $planLimits,
        private readonly DriverAccessResolver $driverAccessResolver,
    ) {}

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
        $isProfileIncomplete = $user && $user->isClient()
            ? blank($user->last_name) || blank($user->city_id) || blank($user->phone) || ! $user->phone_verified_at
            : false;
        $pendingRideRequests = $user?->isDriver() ? RideRequest::pendingIncomingFor($user->id)->count() : 0;
        // Bug real reportado por el usuario (403 en producción): sin el
        // filtro de initiated_by, este contador (y la lista de "Invitaciones
        // recibidas" en Driver/Invitations.vue, ver DriverClientFinder::
        // myClients()) incluía solicitudes que el propio conductor le mandó
        // a un cliente — a esas quien debe responder es el cliente, nunca el
        // conductor (FleetInvitationPolicy::respond()).
        $pendingFleetInvitations = $user?->isDriver()
            ? FleetInvitation::query()->where('driver_user_id', $user->id)->where('status', 'pending')->where('initiated_by', '!=', 'driver')->count()
            : 0;
        $pendingClientFleetRequests = $user?->isClient()
            ? FleetInvitation::query()
                ->where('status', 'pending')
                ->where('initiated_by', 'driver')
                ->whereHas('fleet', fn ($query) => $query->where('owner_user_id', $user->id))
                ->count()
            : 0;
        $pendingCooperativeInvitations = $user?->isDriver()
            ? CooperativeDriverMembership::query()->where('driver_user_id', $user->id)->where('status', 'pending')->count()
            : 0;
        $pendingTrustCircleRequests = $user
            ? TrustCircleConnection::query()->where('addressee_user_id', $user->id)->where('status', 'pending')->count()
            : 0;
        $newDriverRegistrations = $user?->is_admin
            ? User::query()->where('intends_to_drive', true)->whereDoesntHave('driverProfile')->count()
            : 0;
        $driversReadyForVerification = $user?->is_admin
            ? DriverProfile::query()
                ->where('verification_status', 'pending')
                ->get()
                ->filter(fn (DriverProfile $profile) => $profile->hasCompleteRegistrationInformation())
                ->count()
            : 0;

        $notificationItems = collect([
            ['key' => 'rides', 'label' => 'Solicitudes de carrera', 'detail' => 'Carreras esperando tu respuesta', 'count' => $pendingRideRequests, 'url' => route('rides.index')],
            ['key' => 'fleet-driver', 'label' => 'Invitaciones de clientes', 'detail' => 'Solicitudes para entrar a una flota', 'count' => $pendingFleetInvitations, 'url' => route('driver.invitations.index')],
            ['key' => 'fleet-client', 'label' => 'Solicitudes de conductores', 'detail' => 'Conductores que quieren entrar a tu flota', 'count' => $pendingClientFleetRequests, 'url' => route('fleet.index')],
            ['key' => 'cooperative', 'label' => 'Invitaciones de cooperativa', 'detail' => 'Cooperativas esperando tu respuesta', 'count' => $pendingCooperativeInvitations, 'url' => route('rides.index')],
            ['key' => 'trust-circle', 'label' => 'Círculo de confianza', 'detail' => 'Personas que quieren conectar contigo', 'count' => $pendingTrustCircleRequests, 'url' => route('trust-circle.index')],
            ['key' => 'profile', 'label' => 'Perfil incompleto', 'detail' => 'Completa tus datos para usar todas las funciones', 'count' => $isProfileIncomplete ? 1 : 0, 'url' => route('profile.edit')],
            ['key' => 'admin-driver-registrations', 'label' => 'Nuevos conductores', 'detail' => 'Registrados que todavía deben completar su expediente', 'count' => $newDriverRegistrations, 'url' => route('admin.driver-verifications.index')],
            ['key' => 'admin-driver-verifications', 'label' => 'Conductores por verificar', 'detail' => 'Expedientes completos esperando revisión', 'count' => $driversReadyForVerification, 'url' => route('admin.driver-verifications.index')],
        ])->filter(fn (array $item) => $item['count'] > 0)->values();

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
                'isProfileIncomplete' => $isProfileIncomplete,
                // Pedido explícito del usuario: "coloca solicitudes en el
                // navbar... alli donde esta el boton de home" — el badge de
                // la pestaña "Carreras" de la nav inferior necesita este
                // número en CUALQUIER pantalla, no solo en Inicio, así que
                // vive acá y no en DashboardController. Ver
                // RideRequest::pendingIncomingFor() — mismo criterio (algo
                // más simple que el de /carreras) usado también ahí.
                'pendingRideRequestsCount' => $pendingRideRequests,
                // Pedido explícito del usuario: "quitemos carreras y
                // coloquemos clientes" — el tab "Clientes" de la nav
                // inferior (antes "Carreras", redundante con "Solicitudes")
                // necesita este número en cualquier pantalla, mismo
                // criterio que pendingRideRequestsCount de arriba.
                'pendingFleetInvitationsCount' => $pendingFleetInvitations,
                // Centro compacto junto al avatar: un contador único y el
                // desglose navegable evitan repartir puntos sin explicación.
                'notificationSummary' => [
                    'total' => $notificationItems->sum('count'),
                    'items' => $notificationItems->all(),
                ],
                // Pedido explícito del usuario: "eso es para que el sepa
                // que pertenece a una cooperativa, colocalo alli [menú de
                // cuenta] como una etiqueta mas con su enlace... debajo de
                // la que dice conductor" — antes solo vivía en Inicio
                // (Dashboard.vue), ahora en el menú de cuenta se ve en
                // cualquier pantalla.
                'cooperative' => $user?->isDriver()
                    ? CooperativeDriverMembership::activeCooperativeFor($user->id)?->only(['id', 'public_id', 'name'])
                    : null,
                // Pedido explícito del usuario: capacidades del conductor
                // centralizadas en un solo lugar ("no duplicar la lógica de
                // negocio en múltiples componentes") — acceso cooperativa
                // (cubierto por su cooperativa) vs. acceso profesional (plan
                // pagado propio, habilita clientes/flotas privadas). Ver
                // App\Services\Driver\DriverAccessResolver.
                'driverAccess' => $user?->isDriver() ? $this->driverAccessResolver->for($user) : null,
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
