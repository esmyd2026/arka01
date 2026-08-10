<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\FleetMember;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Panel admin de clientes (pedido explícito del usuario): mismo criterio que
 * Admin\DriverController, del otro lado — buscar/filtrar por ciudad, ver
 * fecha de registro y última actividad real, y cuántos conductores tiene
 * cada uno en su flota antes de entrar al detalle completo (admin.users.show,
 * que ya existe).
 */
class ClientController extends Controller
{
    public function index(Request $request): Response
    {
        // 'role' ya es la columna resuelta de siempre (User::isClient() en
        // código; acá el filtro directo por columna evita cargar cada
        // driverProfile solo para descartarlo, con ~cientos de usuarios).
        $query = User::query()
            ->where('role', 'cliente')
            ->with('city')
            ->when($request->filled('q'), function ($query) use ($request) {
                $term = $request->string('q');
                $query->where(fn ($query) => $query->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%"));
            })
            ->when($request->filled('city_id'), fn ($query) => $query->where('city_id', $request->integer('city_id')))
            ->orderBy('name');

        $paginated = $query->paginate(20)->withQueryString();

        $clientIds = collect($paginated->items())->pluck('id');

        // Cuántos conductores tiene cada cliente en su(s) flota(s) — pedido
        // explícito del usuario — por lote, mismo patrón que
        // FleetController::show() usa del lado conductor.
        $driverCounts = FleetMember::query()
            ->join('fleets', 'fleets.id', '=', 'fleet_members.fleet_id')
            ->whereIn('fleets.owner_user_id', $clientIds)
            ->whereNull('fleet_members.left_at')
            ->selectRaw('fleets.owner_user_id, count(*) as drivers_count')
            ->groupBy('fleets.owner_user_id')
            ->pluck('drivers_count', 'fleets.owner_user_id');

        $rideCounts = Ride::query()
            ->whereIn('client_user_id', $clientIds)
            ->where('status', 'completed')
            ->selectRaw('client_user_id, count(*) as rides_count')
            ->groupBy('client_user_id')
            ->pluck('rides_count', 'client_user_id');

        $lastActivity = DB::table('sessions')
            ->whereIn('user_id', $clientIds)
            ->selectRaw('user_id, max(last_activity) as last_activity')
            ->groupBy('user_id')
            ->pluck('last_activity', 'user_id');

        $paginated->through(fn (User $client) => [
            'id' => $client->id,
            'name' => $client->name,
            'email' => $client->email,
            'city' => $client->city?->name,
            'registered_at' => $client->created_at->toIso8601String(),
            'last_active_at' => $lastActivity->has($client->id) ? now()->setTimestamp($lastActivity[$client->id])->toIso8601String() : null,
            'drivers_count' => $driverCounts->get($client->id, 0),
            'completed_rides_count' => $rideCounts->get($client->id, 0),
            'is_locked' => $client->isLocked(),
        ]);

        return Inertia::render('Admin/Clients', [
            'clients' => $paginated,
            'cities' => City::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['q', 'city_id']),
        ]);
    }
}
