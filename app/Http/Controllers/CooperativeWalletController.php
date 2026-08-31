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

        $driverIds = $cooperative->driverMemberships()
            ->whereIn('status', ['accepted', 'suspended'])
            ->pluck('driver_user_id');

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
            // Trazabilidad de TODO el equipo (pedido explícito del
            // usuario) — mismas columnas que ya usa Cooperative/DriverShow.vue
            // por conductor, acá con el nombre del conductor de cada fila.
            'rides' => (clone $rides)
                ->with(['client:id,name', 'driver:id,name'])
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
                    'price' => $ride->chargedTotal(),
                    'status' => $ride->status,
                    'payment_method' => $ride->payment_method,
                    'date' => ($ride->completed_at ?? $ride->cancelled_at ?? $ride->created_at)?->toIso8601String(),
                ]),
        ]);
    }
}
