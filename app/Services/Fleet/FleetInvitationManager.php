<?php

namespace App\Services\Fleet;

use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Models\User;
use App\Notifications\FleetInvitationRespondedPushNotification;
use App\Services\Driver\DriverAccessResolver;
use App\Services\PlanLimits;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo de vida de una invitación/solicitud de flota: cancelar, aceptar,
 * rechazar, salirse, activar/desactivar solicitudes, y "recomendar mi
 * flota" a un amigo — extraído de FleetInvitationController y
 * DriverInvitationController (roadmap app móvil, "full backend": nunca
 * duplicar una regla de negocio entre web y móvil).
 */
class FleetInvitationManager
{
    public function __construct(
        private readonly FleetInvitationCreator $invitationCreator,
        private readonly PlanLimits $planLimits,
        private readonly DriverAccessResolver $driverAccess,
    ) {}

    /**
     * Acepta una invitación/solicitud: recién ahí queda vinculado el
     * conductor a la flota del cliente — nadie entra sin su consentimiento.
     * Quien acepta puede ser el conductor (invitación de siempre, mandada
     * por el cliente) O el cliente (solicitud mandada por el conductor) —
     * por eso los cupos y el `added_by` se resuelven siempre contra los
     * datos de LA INVITACIÓN, nunca contra quien está actuando.
     */
    public function accept(FleetInvitation $invitation): void
    {
        if ($invitation->status !== 'pending') {
            throw ValidationException::withMessages([
                'invitation' => 'Esa invitación ya no está pendiente.',
            ]);
        }

        // Defensa en profundidad (pedido explícito del usuario): mismo
        // chequeo que ya corrió al crear la invitación, repetido acá porque
        // es el momento real en que se crea el FleetMember — cubre el caso
        // borde de que el conductor tuviera plan pagado al invitar pero lo
        // haya perdido antes de aceptar.
        $this->driverAccess->ensureDriverCanBePrivatelyLinked($invitation->driver_user_id, $invitation->fleet);

        // Cupo de clientes de confianza según el plan vigente del conductor.
        // Bloquea la aceptación y sugiere subir de plan.
        $maxClients = $this->planLimits->forDriver($invitation->driver)['max_clients'];

        $activeClientCount = FleetMember::query()
            ->where('driver_user_id', $invitation->driver_user_id)
            ->whereNull('left_at')
            ->count();

        if ($maxClients !== null && $activeClientCount >= $maxClients) {
            throw ValidationException::withMessages([
                'invitation' => 'Llegó al límite de clientes de confianza de su plan. Suba de plan para aceptar más.',
            ]);
        }

        $invitation->update([
            'status' => 'accepted',
            'responded_at' => now(),
        ]);

        FleetMember::query()->create([
            'fleet_id' => $invitation->fleet_id,
            'driver_user_id' => $invitation->driver_user_id,
            // Siempre el dueño de la flota, sin importar quién mandó la
            // invitación/solicitud — es quien "tiene" a ese conductor en su
            // flota, no quien haya iniciado el trámite.
            'added_by' => $invitation->fleet->owner_user_id,
            'joined_at' => now(),
        ]);

        // Quien inició la relación necesita conocer la respuesta aunque no
        // tenga la app abierta.
        $invitation->inviter->notify(new FleetInvitationRespondedPushNotification($invitation, true));
    }

    /**
     * Rechaza una invitación/solicitud — la responde quien no la mandó, sea
     * cliente o conductor (ver FleetInvitationPolicy::respond()).
     */
    public function reject(FleetInvitation $invitation): void
    {
        if ($invitation->status !== 'pending') {
            throw ValidationException::withMessages([
                'invitation' => 'Esa invitación ya no está pendiente.',
            ]);
        }

        $invitation->update([
            'status' => 'rejected',
            'responded_at' => now(),
        ]);

        $invitation->inviter->notify(new FleetInvitationRespondedPushNotification($invitation, false));
    }

    /**
     * El conductor se sale por su cuenta de una flota a la que pertenece —
     * cualquiera de las dos partes puede terminar la relación.
     */
    public function leave(FleetMember $member, User $actingUser): void
    {
        if ($member->driver_user_id !== $actingUser->id) {
            abort(403);
        }

        if (! $member->isActive()) {
            throw ValidationException::withMessages([
                'member' => 'Ya no forma parte de esa flota.',
            ]);
        }

        $member->update([
            'left_at' => now(),
            'left_reason' => 'driver_left',
        ]);
    }

    /**
     * El conductor deja de recibir solicitudes de un cliente puntual sin
     * cortar la relación entera — sigue siendo parte de su flota, solo que
     * sus pedidos ya no le llegan.
     */
    public function toggleRequests(FleetMember $member, User $actingUser): void
    {
        if ($member->driver_user_id !== $actingUser->id) {
            abort(403);
        }

        $member->update(['requests_disabled' => ! $member->requests_disabled]);
    }

    /**
     * El conductor le manda una solicitud a un cliente puntual (buscado)
     * para unirse a su flota — misma tabla, mismas reglas de cupo/
     * duplicados que una invitación normal, dirección opuesta. Si el
     * cliente todavía no tiene ninguna flota propia, se le crea la primera
     * acá mismo — no hace falta que la tenga armada de antemano.
     */
    public function createFromDriver(User $driver, int $clientUserId, ?string $message): FleetInvitation
    {
        $client = User::findOrFail($clientUserId);
        abort_unless($client->isClient(), 404);

        $fleet = Fleet::query()->where('owner_user_id', $client->id)->orderBy('id')->first()
            ?? Fleet::query()->create(['owner_user_id' => $client->id, 'name' => 'Mi flota']);

        return $this->invitationCreator->create($fleet, $driver->id, $driver->id, 'driver', $message);
    }

    /**
     * Quien mandó la invitación/solicitud la cancela mientras nadie respondió.
     */
    public function cancel(FleetInvitation $invitation): void
    {
        if ($invitation->status !== 'pending') {
            throw ValidationException::withMessages([
                'invitation' => 'Esa invitación ya fue respondida, no se puede cancelar.',
            ]);
        }

        $invitation->update([
            'status' => 'cancelled',
            'responded_at' => now(),
        ]);
    }

    /**
     * "Recomendar mi flota": buscar a un amigo (otro cliente) por su
     * usuario o código de socio, SOLO exacto — mismo criterio de privacidad
     * que el resto de la app ("por código nada más, porque chocarían con
     * millones de personas").
     */
    public function searchFriends(User $client, string $term): Collection
    {
        // Acepta "@usuario" o "usuario" por igual.
        $term = ltrim($term, '@');
        $memberCode = ctype_digit($term) ? (int) $term : null;

        return User::query()
            ->where('id', '!=', $client->id)
            ->where('role', 'cliente')
            ->with('city')
            ->when(
                $memberCode,
                fn ($query) => $query->where('member_code', $memberCode),
                fn ($query) => $query->whereRaw('LOWER(username) = ?', [mb_strtolower($term)])
            )
            ->limit(10)
            ->get()
            ->map(fn (User $friend) => [
                'user_id' => $friend->id,
                'name' => $friend->name,
                'avatar_url' => $friend->avatar_url,
                'city' => $friend->city?->name,
                'username' => $friend->username,
                'member_code' => $friend->member_code,
            ])
            ->values();
    }

    /**
     * Envía la recomendación de uno o varios conductores de ESTA flota, a
     * nombre de un amigo (initiated_by = 'referral' — quien invita soy yo,
     * no el dueño de la flota destino, así el conductor sabe quién lo
     * recomendó). Un lote parcialmente inválido no aborta el resto.
     *
     * @return array{sent: int, skipped: int, friend: User}
     */
    public function sendReferral(Fleet $fleet, User $referrer, int $friendUserId, array $driverUserIds, ?string $message): array
    {
        $friend = User::findOrFail($friendUserId);
        abort_unless($friend->isClient() && $friend->id !== $referrer->id, 422);

        // Solo se puede recomendar conductores que YA son parte de la
        // propia flota — no cualquier usuario que exista en la plataforma.
        $eligibleDriverIds = $fleet->activeMembers()
            ->whereIn('driver_user_id', $driverUserIds)
            ->pluck('driver_user_id');

        if ($eligibleDriverIds->isEmpty()) {
            throw ValidationException::withMessages([
                'driver_user_ids' => 'Ninguno de los conductores elegidos es parte de esta flota.',
            ]);
        }

        $friendFleet = Fleet::query()->where('owner_user_id', $friend->id)->orderBy('id')->first()
            ?? Fleet::query()->create(['owner_user_id' => $friend->id, 'name' => 'Mi flota']);

        $sent = 0;
        $skipped = 0;

        foreach ($eligibleDriverIds as $driverId) {
            try {
                $this->invitationCreator->create($friendFleet, (int) $driverId, $referrer->id, 'referral', $message);
                $sent++;
            } catch (ValidationException) {
                // Ya es miembro de la flota del amigo o ya tiene una
                // invitación pendiente ahí — se informa en el resumen, sin
                // frenar el resto del lote.
                $skipped++;
            }
        }

        return ['sent' => $sent, 'skipped' => $skipped, 'friend' => $friend];
    }
}
