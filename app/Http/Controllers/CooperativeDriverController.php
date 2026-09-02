<?php

namespace App\Http\Controllers;

use App\Models\CooperativeDriverMembership;
use App\Models\CooperativeWalletEntry;
use App\Models\DriverActivitySession;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use App\Notifications\CooperativeDriverInvitationPushNotification;
use App\Notifications\CooperativeDriverRemovedPushNotification;
use App\Services\Cooperative\CooperativeDriverResponder;
use App\Services\PlanLimits;
use App\Services\WhatsAppFreeformSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * invitations()/respond() delegan a
 * App\Services\Cooperative\CooperativeDriverResponder (roadmap app móvil,
 * "full backend"). El resto (panel propio de la cuenta Cooperativa) sigue
 * acá tal cual: fuera de alcance del móvil.
 */
class CooperativeDriverController extends Controller
{
    public function __construct(
        private readonly PlanLimits $planLimits,
        private readonly CooperativeDriverResponder $driverResponder,
    ) {}

    public function index(Request $request): Response
    {
        $cooperative = $request->user()->cooperative()->firstOrFail();

        $memberships = $cooperative->driverMemberships()
            ->whereIn('status', ['pending', 'accepted', 'suspended'])
            ->with('driver.driverProfile')
            ->latest()
            ->get();

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

        // Mismas columnas de trazabilidad que Cooperative/Wallet.vue (pedido
        // explícito del usuario: "en cada conductor de cooperativa quiero
        // ver esa tabla también para ver el detalle de las gestiones") — acá
        // ya no hace falta la columna "Conductor" porque la pantalla entera
        // es de un solo conductor.
        $history = (clone $rides)->with(['client:id,name', 'walletEntry'])
            ->latest()->paginate(15)->through(fn (Ride $ride) => [
                'id' => $ride->id,
                'client' => $ride->client?->name ?? 'Cliente',
                'origin' => $ride->origin_address,
                'destination' => $ride->destination_address,
                'distance_km' => (float) $ride->distance_km,
                // Ver CooperativeWalletController::index() — misma columna,
                // mismo criterio (tarifa cotizada real, no la actual del perfil).
                'rate_per_km' => $ride->rate_per_km_snapshot !== null ? (float) $ride->rate_per_km_snapshot : null,
                'price' => $ride->chargedTotal(),
                'driver_pay' => $ride->cooperativeDriverPay(),
                'driver_owes' => $ride->walletEntry?->direction === 'driver_owes_cooperative' ? (float) $ride->walletEntry->amount : 0.0,
                'cooperative_owes' => $ride->walletEntry?->direction === 'cooperative_owes_driver' ? (float) $ride->walletEntry->amount : 0.0,
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
                'earnings_today' => (float) $period($completed, $now->copy()->startOfDay())->selectRaw('COALESCE(SUM('.Ride::chargedTotalSql().'), 0) as total')->value('total'),
                'earnings_week' => (float) $period($completed, $now->copy()->startOfWeek())->selectRaw('COALESCE(SUM('.Ride::chargedTotalSql().'), 0) as total')->value('total'),
                'earnings_month' => (float) $period($completed, $now->copy()->startOfMonth())->selectRaw('COALESCE(SUM('.Ride::chargedTotalSql().'), 0) as total')->value('total'),
                'earnings_total' => (float) (clone $completed)->selectRaw('COALESCE(SUM('.Ride::chargedTotalSql().'), 0) as total')->value('total'),
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
            'memberships' => $this->driverResponder->pendingInvitations($request->user()),
        ]);
    }

    public function respond(Request $request, CooperativeDriverMembership $membership): RedirectResponse
    {
        $validated = $request->validate(['decision' => ['required', 'string', 'in:accept,reject']]);

        $this->driverResponder->respond($membership, $request->user(), $validated['decision']);

        return back()->with('status', $validated['decision'] === 'accept' ? 'Vínculo con la cooperativa aceptado.' : 'Invitación rechazada.');
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

        // Bug real encontrado (no reportado, hallado al implementar el
        // acceso cooperativa vs. independiente): esto se quedaba en
        // 'public_transport' para siempre, aunque la membresía ya hubiera
        // terminado — CooperativeDriverResponder::respond() sí lo pone en
        // 'public_transport' al aceptar, pero nada lo revertía al salir.
        // Solo afecta la etiqueta de confianza mostrada (el acceso real se
        // resuelve aparte, vía DriverAccessResolver sobre la membresía).
        $membership->driver->driverProfile?->update(['driver_type' => 'independent']);

        // Pedido explícito del usuario ("¿se actualiza eso para el
        // conductor?"): el acceso ya se resuelve solo en el siguiente
        // request (DriverAccessResolver), pero antes no había ningún aviso
        // — el conductor se quedaba sin carreras nuevas sin explicación.
        $membership->driver->notify(new CooperativeDriverRemovedPushNotification($membership->cooperative));

        return back()->with('status', 'Conductor retirado de la cooperativa.');
    }

    private function assertOwned(Request $request, CooperativeDriverMembership $membership): void
    {
        abort_unless($request->user()->cooperative?->id === $membership->cooperative_id, 403);
    }
}
