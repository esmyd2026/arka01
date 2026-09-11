<?php

namespace App\Services\Fleet;

use App\Events\FleetInvitationCreated;
use App\Models\DriverProfile;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\FleetMember;
use App\Models\User;
use App\Notifications\FleetInvitationAutoAcceptedPushNotification;
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

        // Pedido explícito del usuario: el conductor puede apagar la
        // aprobación manual desde su perfil (DriverProfile::
        // requires_fleet_invitation_approval) — si lo hizo, y quien inicia
        // esto no es el propio conductor (una solicitud DEL conductor sigue
        // necesitando el sí explícito del cliente, es él quien responde acá:
        // ver FleetInvitation::respondingPartyId()), queda vinculado de una,
        // sin esperar respuesta.
        $autoAccept = $initiatedBy !== 'driver'
            && DriverProfile::where('user_id', $driverUserId)->value('requires_fleet_invitation_approval') === false;

        if ($autoAccept) {
            // Mismo cupo de clientes de confianza que FleetInvitationManager::accept()
            // valida al aceptar a mano — acá se valida ANTES de crear nada
            // porque "aceptar" ocurre en el mismo instante que se invita.
            $driver = User::findOrFail($driverUserId);
            $maxClients = $this->planLimits->forDriver($driver)['max_clients'];
            $activeClientCount = FleetMember::query()->where('driver_user_id', $driverUserId)->whereNull('left_at')->count();

            if ($maxClients !== null && $activeClientCount >= $maxClients) {
                throw ValidationException::withMessages([
                    'driver_user_id' => 'Ese conductor llegó al límite de clientes de confianza de su plan.',
                ]);
            }
        }

        $invitation = FleetInvitation::query()->create([
            'fleet_id' => $fleet->id,
            'driver_user_id' => $driverUserId,
            'invited_by' => $invitedByUserId,
            'initiated_by' => $initiatedBy,
            'message' => $message,
            'status' => $autoAccept ? 'accepted' : 'pending',
            'responded_at' => $autoAccept ? now() : null,
        ]);

        Log::info('Invitación de flota enviada.', [
            'invitation_id' => $invitation->id,
            'fleet_id' => $fleet->id,
            'driver_user_id' => $invitation->driver_user_id,
            'initiated_by' => $initiatedBy,
            'auto_accepted' => $autoAccept,
        ]);

        if ($autoAccept) {
            FleetMember::query()->create([
                'fleet_id' => $fleet->id,
                'driver_user_id' => $driverUserId,
                'added_by' => $fleet->owner_user_id,
                'joined_at' => now(),
            ]);

            $invitation->driver->notify(new FleetInvitationAutoAcceptedPushNotification($invitation));

            return $invitation;
        }

        // Solo tiene sentido avisar en vivo (WebSocket) cuando hay algo
        // pendiente de responder — un auto-aceptado ya no lo está.
        broadcast(new FleetInvitationCreated($invitation))->toOthers();

        $recipient = $initiatedBy === 'driver' ? $fleet->owner : $invitation->driver;
        $recipient->notify(new FleetInvitationPushNotification($invitation));

        return $invitation;
    }
}
