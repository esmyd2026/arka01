<?php

namespace App\Http\Controllers;

use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Models\User;
use App\Services\Fleet\FleetInvitationCreator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FleetInvitationController extends Controller
{
    public function __construct(private readonly FleetInvitationCreator $invitationCreator) {}

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

        $this->invitationCreator->create($fleet, (int) $validated['driver_user_id'], $request->user()->id, 'client', $validated['message'] ?? null);

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

        $this->invitationCreator->create($fleet, $request->user()->id, $request->user()->id, 'driver', $validated['message'] ?? null);

        return back()->with('status', 'Listo — le mandamos la solicitud. Le llega apenas entre.');
    }

    /**
     * "Recomendar mi flota" (pedido explícito del usuario, para gente nueva
     * que no conoce conductores todavía): busco a un amigo (otro cliente) por
     * su usuario o código de socio, SOLO exacto — mismo criterio de
     * privacidad que DriverInvitationController::searchClients() ("por
     * código nada más, porque chocarían con millones de personas"). No hace
     * falta que el amigo sea "mi" cliente ni nada por el estilo, cualquier
     * cliente puede ser el destino de una recomendación.
     */
    public function searchFriends(Request $request, Fleet $fleet)
    {
        $this->authorize('update', $fleet);

        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        // Acepta "@usuario" o "usuario" por igual (pedido explícito del
        // usuario: buscar "por su usuario o código de socio").
        $term = ltrim($validated['q'], '@');
        $memberCode = ctype_digit($term) ? (int) $term : null;
        $userId = $request->user()->id;

        $friends = User::query()
            ->where('id', '!=', $userId)
            ->where('role', 'cliente')
            ->with('city')
            ->when(
                $memberCode,
                fn ($query) => $query->where('member_code', $memberCode),
                fn ($query) => $query->whereRaw('LOWER(username) = ?', [mb_strtolower($term)])
            )
            ->limit(10)
            ->get();

        return response()->json([
            'friends' => $friends->map(fn (User $friend) => [
                'user_id' => $friend->id,
                'name' => $friend->name,
                'avatar_url' => $friend->avatar_url,
                'city' => $friend->city?->name,
                'username' => $friend->username,
                'member_code' => $friend->member_code,
            ])->values(),
        ]);
    }

    /**
     * Envía la recomendación a uno o varios conductores de ESTA flota, a
     * nombre de un amigo (pedido explícito del usuario: "seleccionar todo o
     * uno a uno"). Reusa createInvitation() con initiated_by = 'referral' —
     * quien invita (invited_by) soy YO, no el dueño de la flota destino, así
     * el conductor sabe quién lo recomendó (ver FleetInvitation::inviter()).
     * Un lote parcialmente inválido (un conductor ya es miembro, otro ya
     * tiene invitación pendiente) no aborta el resto — se informan aparte.
     */
    public function storeReferral(Request $request, Fleet $fleet): RedirectResponse
    {
        $this->authorize('update', $fleet);

        $validated = $request->validate([
            'friend_user_id' => ['required', 'integer', 'exists:users,id'],
            'driver_user_ids' => ['required', 'array', 'min:1'],
            'driver_user_ids.*' => ['integer', 'exists:users,id'],
            'message' => ['nullable', 'string', 'max:255'],
        ]);

        $friend = User::findOrFail($validated['friend_user_id']);
        abort_unless($friend->isClient() && $friend->id !== $request->user()->id, 422);

        // Solo puedo recomendar conductores que YA son parte de mi propia
        // flota — no cualquier usuario que exista en la plataforma.
        $eligibleDriverIds = $fleet->activeMembers()
            ->whereIn('driver_user_id', $validated['driver_user_ids'])
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
                $this->invitationCreator->create($friendFleet, (int) $driverId, $request->user()->id, 'referral', $validated['message'] ?? null);
                $sent++;
            } catch (ValidationException) {
                // Ya es miembro de la flota del amigo o ya tiene una
                // invitación pendiente ahí — se informa en el resumen, sin
                // frenar el resto del lote.
                $skipped++;
            }
        }

        $status = $sent > 0
            ? "Se enviaron {$sent} invitación(es) a nombre de {$friend->name}."
            : 'No se envió ninguna invitación nueva.';

        if ($skipped > 0) {
            $status .= " {$skipped} ya eran parte de esa flota o ya tenían una invitación pendiente.";
        }

        return back()->with('status', $status);
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
