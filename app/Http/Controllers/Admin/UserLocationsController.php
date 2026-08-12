<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\User;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedido explícito del usuario: "ver de dónde se registran las personas,
 * por su ubicación" — agrupa a los usuarios por la ciudad más cercana a la
 * ubicación real que dieron al registrarse (ver
 * RegisteredUserController::store()), o por la que hayan elegido a mano
 * después en su perfil si no dieron permiso de ubicación. Mismo criterio de
 * agregación que OperationsController::demandByZone(), aplicado a `users`
 * en vez de a `ride_requests`.
 */
class UserLocationsController extends Controller
{
    public function index(): Response
    {
        // Cuentas admin afuera a propósito: no son "personas que se
        // registraron" en el sentido que le importa a esta pantalla, son
        // cuentas de operación interna.
        $counts = User::query()
            ->where('role', '!=', 'admin')
            ->selectRaw('city_id, count(*) as total')
            ->groupBy('city_id')
            ->pluck('total', 'city_id');

        $cities = City::query()
            ->whereIn('id', $counts->keys()->filter())
            ->get(['id', 'name', 'province', 'lat', 'lng'])
            ->keyBy('id');

        $byCity = $counts
            ->map(function ($total, $cityId) use ($cities) {
                $city = $cityId ? $cities->get($cityId) : null;

                return [
                    'city_id' => $cityId ?: null,
                    'city' => $city?->name ?? ($cityId ? 'Ciudad eliminada' : 'Sin ciudad'),
                    'province' => $city?->province,
                    'lat' => $city?->lat ? (float) $city->lat : null,
                    'lng' => $city?->lng ? (float) $city->lng : null,
                    'total' => (int) $total,
                ];
            })
            ->sortByDesc('total')
            ->values();

        // Barrio/zona aproximado (informativo, resuelto vía OpenStreetMap
        // Nominatim en cola, ver App\Jobs\ResolveRegistrationNeighborhood) —
        // los más frecuentes, para no perderse en un listado de cientos de
        // nombres únicos.
        $topNeighborhoods = User::query()
            ->where('role', '!=', 'admin')
            ->whereNotNull('registration_neighborhood')
            ->selectRaw('registration_neighborhood, count(*) as total')
            ->groupBy('registration_neighborhood')
            ->orderByDesc('total')
            ->limit(15)
            ->get();

        return Inertia::render('Admin/UserLocations', [
            'byCity' => $byCity,
            'totalUsers' => (int) $counts->sum(),
            'usersWithPreciseLocation' => User::query()->where('role', '!=', 'admin')->whereNotNull('registration_lat')->count(),
            'topNeighborhoods' => $topNeighborhoods,
        ]);
    }
}
