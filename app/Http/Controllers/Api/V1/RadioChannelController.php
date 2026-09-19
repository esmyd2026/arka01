<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RadioChannel;
use App\Services\RadioChannelManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Administración del canal de radio personal desde la app móvil (roadmap
 * Hito 5B, paridad con la web). Reusa App\Services\RadioChannelManager, la
 * misma lógica que RadioChannelController (web). La invitación compartible
 * (`radio.invitation.show`) sigue siendo solo web a propósito: se abre
 * desde un enlace externo (WhatsApp) en el navegador del dispositivo,
 * mismo criterio ya aplicado a la landing de referidos.
 */
class RadioChannelController extends Controller
{
    public function __construct(private readonly RadioChannelManager $radioChannels) {}

    public function join(Request $request, RadioChannel $radioChannel): JsonResponse
    {
        $this->radioChannels->join($radioChannel, $request->user());

        return response()->json(['message' => 'Ya forma parte de este canal de seguridad.']);
    }

    public function update(Request $request, RadioChannel $radioChannel): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $this->radioChannels->rename($radioChannel, $request->user(), $validated['name']);

        return response()->json(['message' => 'Nombre del canal actualizado.']);
    }

    public function rotateInvitation(Request $request, RadioChannel $radioChannel): JsonResponse
    {
        $this->radioChannels->rotateInvitation($radioChannel, $request->user());

        return response()->json(['message' => 'El enlace anterior dejó de funcionar.']);
    }

    public function removeMember(Request $request, RadioChannel $radioChannel, string $memberPublicId): JsonResponse
    {
        $this->radioChannels->removeMember($radioChannel, $request->user(), $memberPublicId);

        return response()->json(['message' => 'Integrante retirado del canal.']);
    }

    public function leave(Request $request, RadioChannel $radioChannel): JsonResponse
    {
        $this->radioChannels->leave($radioChannel, $request->user());

        return response()->json(['message' => 'Salió del canal de seguridad.']);
    }
}
