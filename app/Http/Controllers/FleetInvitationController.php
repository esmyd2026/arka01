<?php

namespace App\Http\Controllers;

use App\Events\FleetInvitationCreated;
use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\User;
use App\Notifications\FleetInvitationPushNotification;
use App\Services\PlanLimits;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class FleetInvitationController extends Controller
{
    public function __construct(private readonly PlanLimits $planLimits) {}

    /**
     * El cliente invita a un conductor a una de sus flotas (sección 3.2). Queda
     * "pendiente" hasta que el conductor la acepte — nadie entra a una flota
     * sin su consentimiento.
     */
    public function store(Request $request, Fleet $fleet): RedirectResponse
    {
        $this->authorize('update', $fleet);

        $validated = $request->validate([
            'driver_user_id' => ['required', 'integer', 'exists:users,id'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $this->createInvitation($fleet, (int) $validated['driver_user_id'], $request->user()->id, 'client', $validated['message'] ?? null);

        return back();
    }

    /**
     * Pedido explícito del usuario: además de que un cliente invite a un
     * conductor, el conductor también puede mandarle una solicitud a un
     * cliente puntual (buscado, ver FleetController::searchClients()) para
     * unirse a su flota — misma tabla, mismas reglas de cupo/duplicados,
     * dirección opuesta. Si el cliente todavía no tiene ninguna flota
     * propia, se le crea la primera acá mismo (mismo criterio que
     * ReferralController::store()) — no hace falta que la tenga armada de
     * antemano para poder recibir la solicitud.
     */
    public function storeFromDriver(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isDriver(), 403);

        $validated = $request->validate([
            'client_user_id' => ['required', 'integer', 'exists:users,id'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $client = User::findOrFail($validated['client_user_id']);
        abort_unless($client->isClient(), 404);

        $fleet = Fleet::query()->where('owner_user_id', $client->id)->orderBy('id')->first()
            ?? Fleet::query()->create(['owner_user_id' => $client->id, 'name' => 'Mi flota']);

        $this->createInvitation($fleet, $request->user()->id, $request->user()->id, 'driver', $validated['message'] ?? null);

        return back()->with('status', 'Listo — le mandamos la solicitud. Le llega apenas entre.');
    }

    /**
     * Lo que ya validaba/creaba store() (cupo, ya-es-miembro, ya-invitado,
     * notificación) — ahora reusado por las dos direcciones, con el cupo y
     * el "ya es miembro" siempre resueltos contra $fleet->owner (el cliente
     * real), nunca contra quien está mandando la petición en este momento
     * (que acá puede ser el cliente o el conductor).
     */
    private function createInvitation(Fleet $fleet, int $driverUserId, int $invitedByUserId, string $initiatedBy, ?string $message): FleetInvitation
    {
        // Cupo de conductores por flota según el plan vigente del CLIENTE
        // (sección 3.2, 7.3 y 9.6). Mensaje accionable, no un error genérico (sección 9.7).
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

        // A quien le toca responder — el conductor si fue el cliente quien
        // invitó (de siempre), o el cliente si fue el conductor quien mandó
        // la solicitud. Nunca a quien la mandó: ya sabe que la mandó.
        $recipient = $initiatedBy === 'driver' ? $fleet->owner : $invitation->driver;
        $recipient->notify(new FleetInvitationPushNotification($invitation));

        return $invitation;
    }

    /**
     * Quien mandó la invitación/solicitud la cancela mientras nadie respondió.
     */
    public function destroy(FleetInvitation $invitation): RedirectResponse
    {
        $this->authorize('cancel', $invitation);

        if ($invitation->status !== 'pending') {
            throw ValidationException::withMessages([
                'invitation' => 'Esa invitación ya fue respondida, no se puede cancelar.',
            ]);
        }

        $invitation->update([
            'status' => 'cancelled',
            'responded_at' => now(),
        ]);

        return back();
    }
}
