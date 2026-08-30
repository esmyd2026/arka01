<?php

namespace App\Http\Controllers;

use App\Models\Fleet;
use App\Models\FleetInvitation;
use App\Services\Fleet\FleetInvitationCreator;
use App\Services\Fleet\FleetInvitationManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class FleetInvitationController extends Controller
{
    public function __construct(
        private readonly FleetInvitationCreator $invitationCreator,
        private readonly FleetInvitationManager $invitationManager,
    ) {}

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

        $this->invitationManager->createFromDriver($request->user(), (int) $validated['client_user_id'], $validated['message'] ?? null);

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

        return response()->json(['friends' => $this->invitationManager->searchFriends($request->user(), $validated['q'])]);
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

        $result = $this->invitationManager->sendReferral(
            $fleet,
            $request->user(),
            (int) $validated['friend_user_id'],
            $validated['driver_user_ids'],
            $validated['message'] ?? null,
        );

        $status = $result['sent'] > 0
            ? "Se enviaron {$result['sent']} invitación(es) a nombre de {$result['friend']->name}."
            : 'No se envió ninguna invitación nueva.';

        if ($result['skipped'] > 0) {
            $status .= " {$result['skipped']} ya eran parte de esa flota o ya tenían una invitación pendiente.";
        }

        return back()->with('status', $status);
    }

    /**
     * Quien mandó la invitación/solicitud la cancela mientras nadie respondió.
     */
    public function destroy(FleetInvitation $invitation): RedirectResponse
    {
        $this->authorize('cancel', $invitation);

        $this->invitationManager->cancel($invitation);

        return back();
    }
}
