<?php

namespace App\Services\Profile;

use App\Models\FleetMember;
use App\Models\User;
use App\Services\Trust\TrustIndexCalculator;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Perfil público (sección 3.6): visible para cualquier usuario logueado, no
 * hace falta compartir flota — extraído de PublicProfileController (roadmap
 * app móvil, "full backend": nunca duplicar una regla de negocio entre web
 * y móvil). No incluye la detección de bots de vista previa ni la página
 * HTML aparte para WhatsApp/redes — eso es presentación específica de
 * compartir un enlace desde el navegador, no aplica a un cliente API.
 */
class PublicProfileFinder
{
    public function __construct(private readonly TrustIndexCalculator $trustIndexCalculator) {}

    /**
     * @return array{profileUser: array, profileUrl: string, averageRating: float, reviewCount: int, trustIndex: ?array, mutualPeople: array, reviews: LengthAwarePaginator, profilePrivate: bool, isClient: bool, isDriver: bool}
     */
    public function forUser(User $user, ?User $viewer): array
    {
        $user->load(['driverProfile', 'cooperativeDriverMemberships.cooperative']);
        $activeCooperative = $user->cooperativeDriverMemberships
            ->first(fn ($membership) => $membership->status === 'accepted' && $membership->ended_at === null)?->cooperative;
        $fleetClientsCount = $user->driverProfile
            ? FleetMember::query()->where('driver_user_id', $user->id)->whereNull('left_at')->count()
            : 0;

        // Pedido explícito del usuario ("mejoremos la privacidad de los
        // conductores"): un conductor puede preferir que quien no sea él ni
        // un admin no vea vehículo, tarifa ni comentarios. El dueño del
        // perfil y un admin siguen viendo todo.
        $isOwnerOrAdmin = $viewer?->is($user) || $viewer?->isAdmin();
        $isPrivateDriverProfile = $user->driverProfile && ! $user->driverProfile->profile_public && ! $isOwnerOrAdmin;
        $relationshipViewer = $viewer && ! $viewer->is($user) ? $viewer : null;
        $trustIndex = $isPrivateDriverProfile
            ? null
            : $this->trustIndexCalculator->calculate($user, $relationshipViewer);

        // El perfil sirve como pantalla de decisión antes de aceptar una
        // solicitud. Solo se comparten conexiones aceptadas y campos públicos;
        // nunca correo, teléfono ni una relación pendiente.
        $mutualPeople = [];
        if (! $isPrivateDriverProfile && $relationshipViewer) {
            $mutualIds = $this->trustIndexCalculator->mutualUserIds($user, $relationshipViewer);
            $peopleById = User::query()->whereIn('id', $mutualIds)->get()->keyBy('id');

            $mutualPeople = $mutualIds
                ->map(fn (int $id) => $peopleById->get($id))
                ->filter()
                ->take(12)
                ->map(fn (User $person) => [
                    'public_id' => $person->public_id,
                    'name' => $person->full_name,
                    'username' => $person->username,
                    'avatar_url' => $person->avatar_url,
                ])
                ->values()
                ->all();
        }

        // Perfil privado: ni siquiera se consultan las opiniones — nada de
        // reputación se muestra a quien no sea el dueño ni un admin.
        $reviews = $isPrivateDriverProfile
            ? new LengthAwarePaginator([], 0, 10)
            : $user->reviewsReceived()->with('reviewer')->latest()->paginate(10);

        return [
            // Auditoría de seguridad: esta pantalla es visible para
            // CUALQUIER usuario logueado con cualquier ID en la URL, a
            // propósito — mandar el modelo completo permitía enumerar a
            // toda la base de usuarios. Acá solo va lo que se muestra de
            // verdad.
            'profileUser' => [
                'public_id' => $user->public_id,
                'name' => $user->full_name,
                'username' => $user->username,
                'member_code' => $user->member_code,
                'avatar_url' => $user->avatar_url,
                'driver_profile' => ($user->driverProfile && ! $isPrivateDriverProfile) ? [
                    // Confidencialidad: la foto del vehículo no viaja a
                    // ninguna pantalla de cliente — solo el propio
                    // conductor y un admin la ven. La placa va tapada.
                    'vehicle_make' => $user->driverProfile->vehicle_make,
                    'vehicle_model' => $user->driverProfile->vehicle_model,
                    'vehicle_type' => $user->driverProfile->vehicleTypeLabel(),
                    'vehicle_plate' => $user->driverProfile->maskedPlate(),
                    'rate_per_km' => $user->driverProfile->rate_per_km,
                    'accepts_cash' => $user->driverProfile->accepts_cash,
                    'accepts_transfer' => $user->driverProfile->accepts_transfer,
                    'verification_status' => $user->driverProfile->verification_status,
                    'public_category' => $user->driverProfile->public_category,
                    'public_category_label' => $user->driverProfile->visiblePublicCategoryLabel(),
                    'cooperative' => $activeCooperative ? [
                        'public_id' => $activeCooperative->public_id,
                        'name' => $activeCooperative->name,
                    ] : null,
                    'clients_count' => $fleetClientsCount,
                ] : null,
            ],
            'profileUrl' => route('profiles.show', $user->public_id),
            'averageRating' => $isPrivateDriverProfile ? 0 : round((float) $user->reviewsReceived()->avg('rating'), 1),
            'reviewCount' => $isPrivateDriverProfile ? 0 : $user->reviewsReceived()->count(),
            'trustIndex' => $trustIndex,
            'mutualPeople' => $mutualPeople,
            'reviews' => $reviews,
            'profilePrivate' => $isPrivateDriverProfile,
            // Bug reportado por el usuario (perfil público mostraba las dos
            // insignias "Cliente" y "Conductor" a la vez): usamos el mismo
            // criterio canónico que el resto de la app (User::isClient()).
            'isClient' => $user->isClient(),
            'isDriver' => $user->driverProfile !== null,
        ];
    }
}
