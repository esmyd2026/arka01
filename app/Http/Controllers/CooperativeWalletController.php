<?php

namespace App\Http\Controllers;

use App\Models\CooperativeWalletEntry;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedido explícito del usuario ("lo que habíamos quedado con las
 * cooperativas y sus conductores no está funcionando, donde la cooperativa
 * ve la trazabilidad de las carreras, cuánto hizo su equipo y cuánto le
 * deben o cuánto ella le debe a su equipo"): antes de esta pantalla, la
 * única forma de ver algo de esto era entrar conductor por conductor
 * (Cooperative/DriverShow.vue) — acá va todo el equipo junto: ingresos
 * totales, saldo de billetera agregado y por conductor, y el historial
 * completo de carreras.
 */
class CooperativeWalletController extends Controller
{
    public function index(Request $request): Response
    {
        $cooperative = $request->user()->cooperative()->firstOrFail();

        // Bug real reportado por el usuario ("elimine a mis conductores y se
        // eliminaron mis datos de carreras, no debe ser"): esta pantalla es
        // un registro FINANCIERO, no el roster operativo — tiene que incluir
        // a cualquier conductor que alguna vez perteneció acá (incluido
        // 'removed'/'rejected'), no solo a los actualmente
        // accepted/suspended. Retirar a un conductor (CooperativeDriverController::remove())
        // ya es una baja lógica — nunca borra la fila de membresía ni las
        // carreras/billetera asociadas — el filtro de acá era lo único que
        // las hacía parecer borradas.
        $driverIds = $cooperative->driverMemberships()->pluck('driver_user_id');

        $rides = Ride::query()
            ->whereIn('driver_user_id', $driverIds)
            ->whereHas('rideRequest', fn ($query) => $query->where('cooperative_id', $cooperative->id));
        $completed = (clone $rides)->where('status', 'completed');
        $now = now();

        $period = fn ($query, $start) => (clone $query)->whereBetween('completed_at', [$start, $now]);
        $earningsSum = fn ($query) => (float) $query
            ->selectRaw('COALESCE(SUM('.Ride::chargedTotalSql().'), 0) as total')
            ->value('total');

        // Billetera (pedido explícito del usuario: "cuánto le deben o
        // cuánto ella le debe a su equipo") — total agregado y, cuando hay
        // saldo real, el desglose por conductor para saber a quién le toca
        // qué. Ver App\Models\CooperativeWalletEntry.
        $driverNames = User::query()->whereIn('id', $driverIds)->pluck('name', 'id');
        $walletByDriver = CooperativeWalletEntry::balancesForDrivers($cooperative->id, $driverIds);
        $walletBreakdown = collect($walletByDriver)
            ->filter(fn ($balance) => abs($balance) >= 0.01)
            ->map(fn ($balance, $driverId) => [
                'driver_user_id' => $driverId,
                'driver_name' => $driverNames[$driverId] ?? 'Conductor',
                'balance' => $balance,
            ])
            ->sortByDesc(fn ($row) => abs($row['balance']))
            ->values();

        return Inertia::render('Cooperative/Wallet', [
            'cooperative' => $cooperative,
            'earnings' => [
                'today' => $earningsSum($period($completed, $now->copy()->startOfDay())),
                'week' => $earningsSum($period($completed, $now->copy()->startOfWeek())),
                'month' => $earningsSum($period($completed, $now->copy()->startOfMonth())),
                'total' => $earningsSum(clone $completed),
                'completed_rides' => (clone $completed)->count(),
            ],
            'walletTotal' => CooperativeWalletEntry::totalBalance($cooperative->id),
            'walletByDriver' => $walletBreakdown,
            'paymentStats' => [
                'proofs_to_review' => (clone $completed)->where('payment_method', 'transferencia')->where('payment_status', 'proof_submitted')->count(),
                'cash_to_confirm' => (clone $completed)->where('payment_method', 'efectivo')->where('payment_status', 'pending')->count(),
                'confirmed' => (clone $completed)->where('payment_status', 'confirmed')->count(),
            ],
            'paymentReviews' => (clone $completed)
                ->where('payment_method', 'transferencia')
                ->where('payment_status', 'proof_submitted')
                ->with(['client:id,name', 'driver:id,name'])
                ->latest('payment_proof_uploaded_at')
                ->limit(20)
                ->get()
                ->map(fn (Ride $ride) => [
                    'id' => $ride->id,
                    'client' => $ride->client?->name ?? 'Cliente',
                    'driver' => $ride->driver?->name ?? 'Conductor',
                    'amount' => $ride->chargedTotal(),
                    'proof_url' => route('rides.payment-proof.show', $ride),
                    'uploaded_at' => $ride->payment_proof_uploaded_at?->toIso8601String(),
                    'original_size' => $ride->payment_proof_original_size,
                    'stored_size' => $ride->payment_proof_stored_size,
                ]),
            // Trazabilidad de TODO el equipo (pedido explícito del
            // usuario) — mismas columnas que ya usa Cooperative/DriverShow.vue
            // por conductor, acá con el nombre del conductor de cada fila.
            // Tabla profesional de trazabilidad (pedido explícito del
            // usuario): por carrera, cuánto pagó el cliente, cuánto le
            // correspondía en realidad al conductor, y el movimiento de
            // billetera que dejó esa carrera puntual — no solo el saldo
            // acumulado. `walletEntry` es la única fuente de la diferencia
            // (ver Ride::cooperativeDriverPay()), nunca se vuelve a calcular
            // la tarifa desde cero acá.
            'rides' => (clone $rides)
                ->with(['client:id,name', 'driver:id,name', 'walletEntry'])
                ->latest('completed_at')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (Ride $ride) => [
                    'id' => $ride->id,
                    'driver' => $ride->driver?->name ?? 'Conductor',
                    'client' => $ride->client?->name ?? 'Cliente',
                    'origin' => $ride->origin_address,
                    'destination' => $ride->destination_address,
                    'distance_km' => $ride->distance_km !== null ? (float) $ride->distance_km : null,
                    // Tarifa real cotizada para esta carrera (pedido
                    // explícito del usuario: "el cobro al cliente por km,
                    // según lo configurado para esa carrera") — nunca la
                    // tarifa actual del perfil del conductor, ver
                    // RideRequestResponder::accept().
                    'rate_per_km' => $ride->rate_per_km_snapshot !== null ? (float) $ride->rate_per_km_snapshot : null,
                    'price' => $ride->chargedTotal(),
                    'driver_pay' => $ride->cooperativeDriverPay(),
                    'driver_owes' => $ride->walletEntry?->direction === 'driver_owes_cooperative' ? (float) $ride->walletEntry->amount : 0.0,
                    'cooperative_owes' => $ride->walletEntry?->direction === 'cooperative_owes_driver' ? (float) $ride->walletEntry->amount : 0.0,
                    'status' => $ride->status,
                    'payment_method' => $ride->payment_method,
                    'payment_status' => $ride->payment_status,
                    'payment_proof_url' => $ride->payment_proof_path ? route('rides.payment-proof.show', $ride) : null,
                    'payment_proof_original_size' => $ride->payment_proof_original_size,
                    'payment_proof_stored_size' => $ride->payment_proof_stored_size,
                    'payment_proof_uploaded_at' => $ride->payment_proof_uploaded_at?->toIso8601String(),
                    'payment_confirmed_at' => $ride->payment_confirmed_at?->toIso8601String(),
                    'payment_rejection_reason' => $ride->payment_rejection_reason,
                    'date' => ($ride->completed_at ?? $ride->cancelled_at ?? $ride->created_at)?->toIso8601String(),
                ]),
        ]);
    }
}
