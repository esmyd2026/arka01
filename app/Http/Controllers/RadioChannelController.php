<?php

namespace App\Http\Controllers;

use App\Models\RadioChannel;
use App\Services\RadioChannelManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * join()/update()/rotateInvitation()/removeMember()/leave() delegan a
 * App\Services\RadioChannelManager (roadmap app móvil, "full backend").
 */
class RadioChannelController extends Controller
{
    public function __construct(private readonly RadioChannelManager $radioChannels) {}

    public function showInvitation(Request $request, RadioChannel $radioChannel): Response
    {
        $radioChannel->load('owner:id,public_id,name,last_name,avatar_path,role');
        $user = $request->user();

        if (! $user) {
            // Después del inicio de sesión Laravel volverá a esta invitación.
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return Inertia::render('Radio/Invitation', [
            'channel' => [
                'name' => $radioChannel->name,
                'owner' => [
                    'name' => $radioChannel->owner->full_name,
                    'avatar_url' => $radioChannel->owner->avatar_url,
                    'role' => $radioChannel->owner->isDriver() ? 'conductor' : 'cliente',
                ],
                'member_count' => $radioChannel->members()->count(),
            ],
            'shareCode' => $radioChannel->share_code,
            'canJoin' => $user && ($user->isClient() || $user->isDriver())
                && $user->id !== $radioChannel->owner_user_id,
            'alreadyMember' => $user
                ? $radioChannel->members()->where('user_id', $user->id)->exists()
                : false,
            'isOwner' => $user?->id === $radioChannel->owner_user_id,
        ]);
    }

    public function join(Request $request, RadioChannel $radioChannel): RedirectResponse
    {
        $this->radioChannels->join($radioChannel, $request->user());

        return back()->with('success', 'Ya forma parte de este canal de seguridad.');
    }

    public function update(Request $request, RadioChannel $radioChannel): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:60']]);

        $this->radioChannels->rename($radioChannel, $request->user(), $validated['name']);

        return back()->with('success', 'Nombre del canal actualizado.');
    }

    public function rotateInvitation(Request $request, RadioChannel $radioChannel): RedirectResponse
    {
        $this->radioChannels->rotateInvitation($radioChannel, $request->user());

        return back()->with('success', 'El enlace anterior dejó de funcionar.');
    }

    public function removeMember(Request $request, RadioChannel $radioChannel, string $memberPublicId): RedirectResponse
    {
        $this->radioChannels->removeMember($radioChannel, $request->user(), $memberPublicId);

        return back()->with('success', 'Integrante retirado del canal.');
    }

    public function leave(Request $request, RadioChannel $radioChannel): RedirectResponse
    {
        $this->radioChannels->leave($radioChannel, $request->user());

        return back()->with('success', 'Salió del canal de seguridad.');
    }
}
