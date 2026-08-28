<?php

namespace App\Http\Controllers;

use App\Models\RadioChannel;
use App\Models\RadioChannelMember;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class RadioChannelController extends Controller
{
    private const MAX_MEMBERS = 30;

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
        $user = $request->user();
        abort_unless($user->isClient() || $user->isDriver(), 403);
        abort_if($radioChannel->owner_user_id === $user->id, 422, 'Este ya es su canal principal.');

        if (! $radioChannel->members()->where('user_id', $user->id)->exists()) {
            abort_if($radioChannel->members()->count() >= self::MAX_MEMBERS, 409, 'El canal alcanzó su límite de integrantes.');

            $radioChannel->members()->create([
                'user_id' => $user->id,
                'joined_at' => now(),
            ]);
        }

        return back()->with('success', 'Ya forma parte de este canal de seguridad.');
    }

    public function update(Request $request, RadioChannel $radioChannel): RedirectResponse
    {
        $this->authorizeOwner($request->user(), $radioChannel);
        $validated = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $radioChannel->update(['name' => Str::squish($validated['name'])]);

        return back()->with('success', 'Nombre del canal actualizado.');
    }

    public function rotateInvitation(Request $request, RadioChannel $radioChannel): RedirectResponse
    {
        $this->authorizeOwner($request->user(), $radioChannel);
        $radioChannel->rotateShareCode();

        return back()->with('success', 'El enlace anterior dejó de funcionar.');
    }

    public function removeMember(Request $request, RadioChannel $radioChannel, string $memberPublicId): RedirectResponse
    {
        $this->authorizeOwner($request->user(), $radioChannel);
        $member = User::query()->where('public_id', $memberPublicId)->firstOrFail();
        $radioChannel->members()->where('user_id', $member->id)->delete();

        return back()->with('success', 'Integrante retirado del canal.');
    }

    public function leave(Request $request, RadioChannel $radioChannel): RedirectResponse
    {
        RadioChannelMember::query()
            ->where('radio_channel_id', $radioChannel->id)
            ->where('user_id', $request->user()->id)
            ->delete();

        return back()->with('success', 'Salió del canal de seguridad.');
    }

    private function authorizeOwner(User $user, RadioChannel $radioChannel): void
    {
        abort_unless($radioChannel->owner_user_id === $user->id, 403);
    }
}
