<?php

namespace App\Http\Controllers;

use App\Models\CooperativeDriverMembership;
use App\Models\CooperativeWalletEntry;
use App\Models\DriverActivitySession;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use App\Notifications\CooperativeDriverInvitationPushNotification;
use App\Notifications\CooperativeDriverResponsePushNotification;
use App\Services\PlanLimits;
use App\Services\WhatsAppFreeformSender;
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

        $memberships = $cooperative->driverMemberships()
            ->whereIn('status', ['pending', 'accepted', 'suspended'])
            ->with('driver.driverProfile')
            ->latest()
            ->get();

        // Pedido explícito del usuario: "cuando una cooperativa tenga que
        // buscar a un conductor... tiene que tener el plan mayor al
        // gratis, y tiene que estar vigente. por lo contrario aparecera
        // bloqueado" — se calcula siempre en vivo (nunca se guarda, mismo
        // criterio que cooperativeDriverDiscountPercent()), y solo aplica a
        // vínculos 'accepted' (uno 'pending'/'suspended' ya está sin recibir
        // carreras por otro motivo, no hace falta marcarlo también).
        $memberships->each(function ($membership) {
            $membership->is_plan_blocked = $membership->status === 'accepted'
                && ! $this->planLimits->hasActivePaidPlan($membership->driver);
        });

        return Inertia::render('Cooperative/Drivers', [
            'cooperative' => $cooperative,
            'memberships' => $memberships,
            'planLimits' => $this->planLimits->forCooperative($request->user()),
        ]);
    }

    public function show(Request $request, CooperativeDriverMembership $membership): Response
    {
        $this->assertOwned($request, $membership);
        $membership->load('driver.driverProfile');
        $driver = $membership->driver;
        $cooperativeId = $membership->cooperative_id;

        $rides = Ride::query()
            ->where('driver_user_id', $driver->id)
            ->whereHas('rideRequest', fn ($query) => $query->where('cooperative_id', $cooperativeId));
        $completed = (clone $rides)->where('status', 'completed');
        $now = now();

        $period = function ($query, $start) use ($now) {
            return (clone $query)->whereBetween('completed_at', [$start, $now]);
        };

        $activitySessions = DriverActivitySession::query()
            ->where('driver_user_id', $driver->id)
            ->orderBy('started_at')
            ->get();
        $activeMinutes = function ($start = null) use ($activitySessions, $now): int {
            return (int) round($activitySessions->sum(function ($session) use ($start, $now) {
                $from = $start && $session->started_at->lt($start) ? $start->copy() : $session->started_at;
                $to = $session->ended_at ?? $session->last_seen_at ?? $now;

                return $to->gt($from) ? $from->diffInSeconds($to) : 0;
            }) / 60);
        };

        $history = (clone $rides)->with('client:id,name')
            ->latest()->paginate(15)->through(fn (Ride $ride) => [
                'id' => $ride->id,
                'client' => $ride->client?->name ?? 'Cliente',
                'origin' => $ride->origin_address,
                'destination' => $ride->destination_address,
                'distance_km' => (float) $ride->distance_km,
                'price' => (float) $ride->price,
                'status' => $ride->status,
                'payment_method' => $ride->payment_method,
                'date' => ($ride->completed_at ?? $ride->cancelled_at ?? $ride->created_at)?->toIso8601String(),
            ]);

        return Inertia::render('Cooperative/DriverShow', [
            'membership' => $membership,
            'summary' => [
                'rides_today' => $period($completed, $now->copy()->startOfDay())->count(),
                'rides_month' => $period($completed, $now->copy()->startOfMonth())->count(),
                'assigned_total' => RideRequest::query()->where('cooperative_id', $cooperativeId)->where('driver_user_id', $driver->id)->count(),
                'completed_total' => (clone $completed)->count(),
                'cancelled_total' => (clone $rides)->where('status', 'cancelled')->count(),
                'earnings_today' => (float) $period($completed, $now->copy()->startOfDay())->sum('price'),
                'earnings_week' => (float) $period($completed, $now->copy()->startOfWeek())->sum('price'),
                'earnings_month' => (float) $period($completed, $now->copy()->startOfMonth())->sum('price'),
                'earnings_total' => (float) (clone $completed)->sum('price'),
                'active_minutes_today' => $activeMinutes($now->copy()->startOfDay()),
                'active_minutes_week' => $activeMinutes($now->copy()->startOfWeek()),
                'active_minutes_month' => $activeMinutes($now->copy()->startOfMonth()),
                'active_minutes_total' => $activeMinutes(),
                'activity_tracking_since' => $activitySessions->first()?->started_at?->toIso8601String(),
                // Billetera (pedido explícito del usuario): positivo = el
                // conductor le debe a la cooperativa, negativo = la
                // cooperativa le debe al conductor. Ver
                // App\Models\CooperativeWalletEntry::balanceFor().
                'wallet_balance' => CooperativeWalletEntry::balanceFor($cooperativeId, $driver->id),
            ],
            'rides' => $history,
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
        // Bug reportado por el usuario ("no le llega la solicitud"): el
        // Web Push solo falla en silencio si el conductor nunca dio
        // permiso — WhatsApp no depende de ningún permiso del navegador.
        WhatsAppFreeformSender::sendCooperativeInvitationAlert($driver, $membership);

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
