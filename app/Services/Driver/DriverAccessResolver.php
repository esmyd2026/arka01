<?php

namespace App\Services\Driver;

use App\Models\ClientCooperative;
use App\Models\CooperativeDriverMembership;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\RideRequest;
use App\Models\User;
use App\Services\PlanLimits;
use Illuminate\Validation\ValidationException;

/**
 * Única fuente de verdad para "¿qué puede hacer este conductor?" (pedido
 * explícito del usuario: diferenciar acceso cooperativa vs. acceso
 * profesional, sin duplicar la lógica en cada controller/componente).
 * Mismo patrón que App\Services\PlanLimits: resuelve en vivo a partir de
 * datos que ya existen (CooperativeDriverMembership, Subscription,
 * FleetMember), nunca persiste un resultado — así nunca se puede
 * desincronizar.
 *
 * Corrección explícita del usuario sobre una primera versión de esta clase:
 * TODO plan de conductor, incluido el gratuito, ya trae su propia capacidad
 * de clientes privados configurada (`PlanLimits::forDriver()['max_clients']`
 * — el plan "gratis" hoy permite 5, no cero). "Acceso cooperativa" nunca
 * significó "cero clientes privados hasta pagar" — significa que la
 * cooperativa cubre el ecosistema cooperativo aparte, sin que eso reemplace
 * ni bloquee la capacidad privada que el conductor YA tiene por su propio
 * plan. La única restricción real entre cooperativa y flota privada es la
 * anti-captación (ver ensureDriverCanBePrivatelyLinked): esta clase nunca
 * inventa un segundo sistema de límites, ni asume un número fijo por plan.
 */
class DriverAccessResolver
{
    public const PERMISSION_COOPERATIVE_RIDES_RECEIVE = 'cooperative.rides.receive';

    public const PERMISSION_COOPERATIVE_RIDES_ACCEPT = 'cooperative.rides.accept';

    public const PERMISSION_COOPERATIVE_RADIO_ACCESS = 'cooperative.radio.access';

    public const PERMISSION_PRIVATE_CLIENTS_MANAGE = 'private.clients.manage';

    public const PERMISSION_PRIVATE_FLEET_JOIN = 'private.fleet.join';

    public const PERMISSION_PRIVATE_REQUESTS_RECEIVE = 'private.requests.receive';

    public function __construct(private readonly PlanLimits $planLimits) {}

    /**
     * @return array{type: string, cooperative_access: bool, professional_access: bool, plan: array{code: string, name: string}, cooperative: array{id: int, public_id: string, name: string, member_since: ?string}|null, cooperatives: array<int, array{id: int, public_id: string, name: string, member_since: ?string}>, private_clients: array{current: int, limit: ?int, remaining: ?int, can_add: bool}, permissions: array<int, string>}
     */
    public function for(User $driver): array
    {
        // Normalmente una sola fila (el límite de siempre), pero un plan con
        // `multi_cooperative_enabled` puede dejar más de una activa a la vez
        // (pedido explícito del usuario) — ver
        // CooperativeDriverMembership::activeMembershipsFor().
        $memberships = CooperativeDriverMembership::activeMembershipsFor($driver->id);
        $membership = $memberships->first();
        $planLimits = $this->planLimits->forDriver($driver);

        $cooperativeAccess = $membership !== null;
        // Informativo (¿tiene algún plan más allá del gratuito?) — ya NO se
        // usa para habilitar o no clientes privados, eso lo resuelve
        // `private_clients` de abajo con la capacidad real del plan.
        $professionalAccess = $this->hasProfessionalAccess($driver);

        $mapCooperative = fn (CooperativeDriverMembership $membership) => [
            'id' => $membership->cooperative->id,
            'public_id' => $membership->cooperative->public_id,
            'name' => $membership->cooperative->name,
            'member_since' => $membership->responded_at?->toIso8601String(),
        ];

        return [
            'type' => match (true) {
                $cooperativeAccess && $professionalAccess => 'both',
                $cooperativeAccess => 'cooperative',
                $professionalAccess => 'professional',
                default => 'basic',
            },
            'cooperative_access' => $cooperativeAccess,
            'professional_access' => $professionalAccess,
            'plan' => [
                'code' => $planLimits['plan_code'],
                'name' => $planLimits['plan_name'],
            ],
            // La principal (compatibilidad con lo que ya consumía este
            // campo cuando solo existía una) — para el caso normal es la
            // única. `cooperatives` de abajo trae la lista completa.
            'cooperative' => $membership ? $mapCooperative($membership) : null,
            'cooperatives' => $memberships->map($mapCooperative)->values()->all(),
            'private_clients' => $this->privateClientCapacity($driver, $planLimits),
            // Cualquier conductor (cooperativa o no) siempre tiene, en algún
            // grado, capacidad privada configurada por su plan — la lista de
            // permisos ya no la condiciona un plan pagado, solo si hay
            // afiliación de cooperativa para el bloque cooperativo.
            'permissions' => [
                ...($cooperativeAccess ? [
                    self::PERMISSION_COOPERATIVE_RIDES_RECEIVE,
                    self::PERMISSION_COOPERATIVE_RIDES_ACCEPT,
                    self::PERMISSION_COOPERATIVE_RADIO_ACCESS,
                ] : []),
                self::PERMISSION_PRIVATE_CLIENTS_MANAGE,
                self::PERMISSION_PRIVATE_FLEET_JOIN,
                self::PERMISSION_PRIVATE_REQUESTS_RECEIVE,
            ],
        ];
    }

    /** Informativo: ¿tiene una suscripción activa distinta de "gratis"? No gatea clientes privados. */
    public function hasProfessionalAccess(User $driver): bool
    {
        return $this->planLimits->hasActivePaidPlan($driver);
    }

    /**
     * Capacidad de cartera privada — SIEMPRE la del plan real vigente
     * (`PlanLimits::forDriver()['max_clients']`, ya configurada por plan
     * desde /admin, gratis incluido). `limit: null` = sin tope (plan
     * Institucional). Los clientes atendidos vía cooperativa NUNCA cuentan
     * acá — solo `FleetMember` activos (cartera privada propia).
     *
     * @return array{current: int, limit: ?int, remaining: ?int, can_add: bool}
     */
    public function privateClientCapacity(User $driver, ?array $planLimits = null): array
    {
        $limit = ($planLimits ?? $this->planLimits->forDriver($driver))['max_clients'];
        $current = FleetMember::query()->where('driver_user_id', $driver->id)->whereNull('left_at')->count();

        return [
            'current' => $current,
            'limit' => $limit,
            'remaining' => $limit === null ? null : max(0, $limit - $current),
            'can_add' => $limit === null || $current < $limit,
        ];
    }

    /**
     * Bloquea que un conductor se vincule a una flota PRIVADA (invitación
     * del cliente, solicitud del conductor, o referido — los tres pasan por
     * App\Services\Fleet\FleetInvitationCreator::create(), el único punto
     * compartido) por la regla anti-captación, en sus dos formas:
     *
     * 1. El cliente lo conoció por una carrera de COOPERATIVA
     *    (RideRequest.cooperative_id) — esa relación es de la cooperativa,
     *    no una captación del conductor.
     * 2. Pedido explícito del usuario ("asegurate que no se tome los
     *    clientes de la cooperativa a la que tiene afiliación... los que
     *    tiene la cooperativa el no los puede tomar"): el cliente ya tiene
     *    agregada a SU red una cooperativa a la que el conductor está
     *    afiliado (ClientCooperative), aunque nunca hayan compartido una
     *    carrera todavía — esa cartera comercial ya es de la cooperativa.
     *
     * En ambos casos, si YA existía un FleetMember anterior entre ambos
     * (aunque haya terminado), no se bloquea — la relación privada no nació
     * de la cooperativa. Aplica sin importar el plan del conductor (ni
     * siquiera un plan superior habilita esto) — la capacidad de cupo
     * (cuántos clientes puede tener) la sigue resolviendo el cupo real del
     * plan en FleetInvitationManager::accept(), no esta guarda.
     */
    /**
     * Versión sin excepción de ensureDriverCanBePrivatelyLinked() — pedido
     * explícito del usuario: en la búsqueda de "Mi flota" el conductor
     * capturado por su cooperativa debía seguir apareciendo en los
     * resultados, pero SIN el botón "Invitar" (antes aparecía el botón y
     * fallaba recién al tocarlo, con un error que no explicaba nada en la
     * lista). Ver App\Services\Fleet\FleetDriverSearch::search().
     */
    public function canBePrivatelyLinked(int $driverUserId, Fleet $fleet): bool
    {
        try {
            $this->ensureDriverCanBePrivatelyLinked($driverUserId, $fleet);

            return true;
        } catch (ValidationException) {
            return false;
        }
    }

    public function ensureDriverCanBePrivatelyLinked(int $driverUserId, Fleet $fleet): void
    {
        $clientUserId = $fleet->owner_user_id;

        $cameFromCooperativeRide = RideRequest::query()
            ->where('client_user_id', $clientUserId)
            ->where('driver_user_id', $driverUserId)
            ->whereNotNull('cooperative_id')
            ->exists();

        $driverCooperativeIds = CooperativeDriverMembership::activeMembershipsFor($driverUserId)->pluck('cooperative_id');

        $clientBelongsToDriversCooperative = $driverCooperativeIds->isNotEmpty()
            && ClientCooperative::query()
                ->where('client_user_id', $clientUserId)
                ->whereIn('cooperative_id', $driverCooperativeIds)
                ->exists();

        if (! $cameFromCooperativeRide && ! $clientBelongsToDriversCooperative) {
            return;
        }

        $hadPriorPrivateLink = FleetMember::query()
            ->where('driver_user_id', $driverUserId)
            ->whereHas('fleet', fn ($query) => $query->where('owner_user_id', $clientUserId))
            ->exists();

        if ($hadPriorPrivateLink) {
            return;
        }

        throw ValidationException::withMessages([
            'driver_user_id' => $cameFromCooperativeRide
                ? 'Este cliente lo conoció a través de una carrera de cooperativa — esa relación sigue siendo de la cooperativa, no se puede convertir en flota privada.'
                : 'Este cliente ya tiene agregada a su red la cooperativa a la que usted está afiliado — esa cartera es de la cooperativa, no se puede convertir en flota privada.',
        ]);
    }
}
