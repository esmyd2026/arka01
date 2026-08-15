<?php

namespace App\Http\Controllers;

use App\Models\CooperativeDriverMembership;
use App\Models\User;
use App\Notifications\CooperativeDriverInvitationPushNotification;
use App\Notifications\CooperativeDriverResponsePushNotification;
use App\Services\PlanLimits;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CooperativeDriverController extends Controller
{
    public function __construct(private readonly PlanLimits $planLimits) {}

    public function index(Request $request): Response
    {
        $cooperative = $request->user()->cooperative()->firstOrFail();

        return Inertia::render('Cooperative/Drivers', [
            'cooperative' => $cooperative,
            'memberships' => $cooperative->driverMemberships()
                ->whereIn('status', ['pending', 'accepted', 'suspended'])
                ->with('driver.driverProfile')
                ->latest()
                ->get(),
            'planLimits' => $this->planLimits->forCooperative($request->user()),
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);
        $term = trim($request->string('q')->toString());
        $cooperative = $request->user()->cooperative()->firstOrFail();

        $drivers = User::query()
            ->whereHas('driverProfile', fn ($query) => $query->whereNull('suspended_at')->whereNull('deactivated_at'))
            ->where(fn ($query) => $query->where('name', 'like', "%{$term}%")
                ->orWhere('username', 'like', "%{$term}%")
                ->orWhere('member_code', 'like', "%{$term}%"))
            ->with('driverProfile')
            ->limit(20)
            ->get()
            ->map(function (User $driver) use ($cooperative) {
                $membership = CooperativeDriverMembership::query()
                    ->where('cooperative_id', $cooperative->id)
                    ->where('driver_user_id', $driver->id)
                    ->first();

                return [
                    'id' => $driver->id,
                    'name' => $driver->name,
                    'username' => $driver->username,
                    'member_code' => $driver->member_code,
                    'avatar_url' => $driver->avatar_url,
                    'driver_type' => $driver->driverProfile->driver_type,
                    'verification_status' => $driver->driverProfile->verification_status,
                    'membership_status' => $membership?->status,
                ];
            });

        return response()->json($drivers);
    }

    public function invite(Request $request): RedirectResponse
    {
        $validated = $request->validate(['driver_user_id' => ['required', 'integer', 'exists:users,id']]);
        $cooperative = $request->user()->cooperative()->firstOrFail();
        abort_unless($cooperative->isApproved(), 403, 'La cooperativa debe estar aprobada para vincular conductores.');

        $driver = User::query()->with('driverProfile')->findOrFail($validated['driver_user_id']);
        abort_unless($driver->driverProfile && ! $driver->driverProfile->isSuspended(), 422);

        $limits = $this->planLimits->forCooperative($request->user());
        $occupied = $cooperative->driverMemberships()->whereIn('status', ['pending', 'accepted'])->count();
        if ($limits['max_units'] !== null && $occupied >= $limits['max_units']) {
            throw ValidationException::withMessages(['driver_user_id' => "El plan permite hasta {$limits['max_units']} unidades/conductores."]);
        }

        $membership = CooperativeDriverMembership::query()->updateOrCreate(
            ['cooperative_id' => $cooperative->id, 'driver_user_id' => $driver->id],
            [
                'invited_by_user_id' => $request->user()->id,
                'status' => 'pending',
                'responded_at' => null,
                'suspended_at' => null,
                'ended_at' => null,
            ],
        );

        $membership->load('cooperative');
        $driver->notify(new CooperativeDriverInvitationPushNotification($membership));

        return back()->with('status', 'Invitación enviada al conductor.');
    }

    public function invitations(Request $request): Response
    {
        abort_unless($request->user()->isDriver(), 403);

        return Inertia::render('Cooperative/DriverInvitations', [
            'memberships' => $request->user()->cooperativeDriverMemberships()
                ->whereIn('status', ['pending', 'accepted', 'suspended'])
                ->with('cooperative.city')
                ->latest()
                ->get(),
        ]);
    }

    public function respond(Request $request, CooperativeDriverMembership $membership): RedirectResponse
    {
        abort_unless($membership->driver_user_id === $request->user()->id, 403);
        abort_unless($membership->status === 'pending', 422);

        $validated = $request->validate(['decision' => ['required', 'string', Rule::in(['accept', 'reject'])]]);
        $accepted = $validated['decision'] === 'accept';

        if ($accepted) {
            $alreadyActive = CooperativeDriverMembership::query()
                ->where('driver_user_id', $request->user()->id)
                ->where('status', 'accepted')
                ->whereNull('ended_at')
                ->where('id', '!=', $membership->id)
                ->exists();

            if ($alreadyActive) {
                throw ValidationException::withMessages(['membership' => 'Ya pertenece a otra cooperativa activa. Primero debe finalizar ese vínculo.']);
            }
        }

        $membership->forceFill([
            'status' => $accepted ? 'accepted' : 'rejected',
            'responded_at' => now(),
            'suspended_at' => null,
            'ended_at' => $accepted ? null : now(),
        ])->save();

        if ($accepted) {
            $request->user()->driverProfile->forceFill(['driver_type' => 'public_transport'])->save();
        }

        $membership->load(['driver', 'cooperative.user']);
        $membership->cooperative->user->notify(new CooperativeDriverResponsePushNotification($membership, $accepted));

        return back()->with('status', $accepted ? 'Vínculo con la cooperativa aceptado.' : 'Invitación rechazada.');
    }

    public function suspend(Request $request, CooperativeDriverMembership $membership): RedirectResponse
    {
        $this->assertOwned($request, $membership);
        $membership->forceFill(['status' => 'suspended', 'suspended_at' => now()])->save();

        return back()->with('status', 'Conductor suspendido dentro de la cooperativa.');
    }

    public function reactivate(Request $request, CooperativeDriverMembership $membership): RedirectResponse
    {
        $this->assertOwned($request, $membership);
        $membership->forceFill(['status' => 'accepted', 'suspended_at' => null])->save();

        return back()->with('status', 'Conductor reactivado.');
    }

    public function remove(Request $request, CooperativeDriverMembership $membership): RedirectResponse
    {
        $this->assertOwned($request, $membership);
        $membership->forceFill(['status' => 'removed', 'ended_at' => now(), 'suspended_at' => null])->save();

        return back()->with('status', 'Conductor retirado de la cooperativa.');
    }

    private function assertOwned(Request $request, CooperativeDriverMembership $membership): void
    {
        abort_unless($request->user()->cooperative?->id === $membership->cooperative_id, 403);
    }
}
