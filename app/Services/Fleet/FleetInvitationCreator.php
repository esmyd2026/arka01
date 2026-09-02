<?php

namespace App\Services\Fleet;

use App\Events\FleetInvitationCreated;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Notifications\FleetInvitationPushNotification;
use App\Services\Driver\DriverAccessResolver;
use App\Services\PlanLimits;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Crea una invitación de flota — extraído de
 * FleetInvitationController::createInvitation() (roadmap Hito 2/5: nunca
 * duplicar una regla de negocio entre web y móvil). Cupo de conductores del
 * plan del cliente, ya-es-miembro, ya-invitado-pendiente, notificación push
 * a quien le toca responder: exactamente igual sin importar el canal.
 */
class FleetInvitationCreator
{
    public function __construct(
        private readonly PlanLimits $planLimits,
        private readonly DriverAccessResolver $driverAccess,
    ) {}

    public function create(Fleet $fleet, int $driverUserId, int $invitedByUserId, string $initiatedBy, ?string $message): FleetInvitation
    {
        // Pedido explícito del usuario: un conductor cubierto solo por su
        // cooperativa no puede sumarse a flotas privadas de clientes (pagaría
        // dos veces por el mismo trabajo), y aunque tenga plan profesional,
        // no puede convertir en cliente propio a alguien que lo conoció por
        // una carrera de cooperativa — ver DriverAccessResolver. Primero,
        // antes que cualquier cupo: no tiene sentido gastar cupo del cliente
        // en una invitación que de todas formas no se puede aceptar.
        $this->driverAccess->ensureDriverCanBePrivatelyLinked($driverUserId, $fleet);

        $maxDriversPerFleet = $this->planLimits->forClient($fleet->owner)['max_drivers_per_fleet'];

        if ($maxDriversPerFleet !== null && $fleet->activeMemberCount() >= $maxDriversPerFleet) {
            throw ValidationException::withMessages([
                'driver_user_id' => 'Esa flota llegó al límite de conductores de su plan.',
            ]);
        }

        $alreadyMember = $fleet->activeMembers()
            ->where('driver_user_id', $driverUserId)
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages([
                'driver_user_id' => 'Ya es parte de esa flota.',
            ]);
        }

        $alreadyInvited = $fleet->invitations()
            ->where('driver_user_id', $driverUserId)
            ->where('status', 'pending')
            ->exists();

        if ($alreadyInvited) {
            throw ValidationException::withMessages([
                'driver_user_id' => 'Ya hay una invitación pendiente entre estas dos cuentas.',
            ]);
        }

        $invitation = FleetInvitation::query()->create([
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driverUserId,
            'invited_by' => $invitedByUserId,
            'initiated_by' => $initiatedBy,
            'message' => $message,
            'status' => 'pending',
        ]);

        Log::info('Invitación de flota enviada.', [
            'invitation_id' => $invitation->id,
            'fleet_id' => $fleet->id,
            'driver_user_id' => $invitation->driver_user_id,
            'initiated_by' => $initiatedBy,
        ]);

        broadcast(new FleetInvitationCreated($invitation))->toOthers();

        $recipient = $initiatedBy === 'driver' ? $fleet->owner : $invitation->driver;
        $recipient->notify(new FleetInvitationPushNotification($invitation));

        return $invitation;
    }
}
