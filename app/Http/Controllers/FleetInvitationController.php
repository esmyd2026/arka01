<?php

namespace App\Http\Controllers;

use App\Events\FleetInvitationCreated;
use App\Models\Fleet;
use App\Models\FleetInvitation;
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

        // Cupo de conductores por flota según el plan vigente del CLIENTE
        // (sección 3.2, 7.3 y 9.6). Mensaje accionable, no un error genérico (sección 9.7).
        $maxDriversPerFleet = $this->planLimits->forClient($request->user())['max_drivers_per_fleet'];

        if ($maxDriversPerFleet !== null && $fleet->activeMemberCount() >= $maxDriversPerFleet) {
            throw ValidationException::withMessages([
                'driver_user_id' => 'Llegó al límite de conductores de esta flota. Saque a alguien o suba de plan para invitar a más.',
            ]);
        }

        $alreadyMember = $fleet->activeMembers()
            ->where('driver_user_id', $validated['driver_user_id'])
            ->exists();

        if ($alreadyMember) {
            throw ValidationException::withMessages([
                'driver_user_id' => 'Ese conductor ya es parte de su flota.',
            ]);
        }

        $alreadyInvited = $fleet->invitations()
            ->where('driver_user_id', $validated['driver_user_id'])
            ->where('status', 'pending')
            ->exists();

        if ($alreadyInvited) {
            throw ValidationException::withMessages([
                'driver_user_id' => 'Ya le mandó una invitación a ese conductor y todavía no respondió.',
            ]);
        }

        $invitation = FleetInvitation::query()->create([
            'fleet_id' => $fleet->id,
            'driver_user_id' => $validated['driver_user_id'],
            'invited_by' => $request->user()->id,
            'message' => $validated['message'] ?? null,
            'status' => 'pending',
        ]);

        Log::info('Invitación de flota enviada.', [
            'invitation_id' => $invitation->id,
            'fleet_id' => $fleet->id,
            'driver_user_id' => $invitation->driver_user_id,
        ]);

        broadcast(new FleetInvitationCreated($invitation))->toOthers();
        $invitation->driver->notify(new FleetInvitationPushNotification($invitation));

        return back();
    }

    /**
     * El cliente cancela una invitación que todavía no fue respondida.
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
