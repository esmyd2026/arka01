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

        // Pedido explícito del usuario ("necesito saber de dónde se
        // registran los usuarios, país, provincia, ciudad y coordenadas...
        // para determinar los sectores o ciudades") — el registro
        // individual con su coordenada real, no solo el agregado por
        // ciudad de arriba. Ecuador es el único país que opera la
        // plataforma hoy (sección 1 del alcance), así que "país" es fijo,
        // no una columna — no tiene sentido guardar un dato que nunca varía.
        $registrations = User::query()
            ->where('role', '!=', 'admin')
            ->whereNotNull('registration_lat')
            ->whereNotNull('registration_lng')
            ->with('city:id,name,province')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'city_id', 'registration_lat', 'registration_lng', 'registration_neighborhood', 'created_at'])
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'country' => 'Ecuador',
                'province' => $user->city?->province,
                'city' => $user->city?->name,
                'neighborhood' => $user->registration_neighborhood,
                'lat' => (float) $user->registration_lat,
                'lng' => (float) $user->registration_lng,
                'registered_at' => $user->created_at->toIso8601String(),
            ]);

        return Inertia::render('Admin/UserLocations', [
            'byCity' => $byCity,
            'totalUsers' => (int) $counts->sum(),
            'usersWithPreciseLocation' => User::query()->where('role', '!=', 'admin')->whereNotNull('registration_lat')->count(),
            'topNeighborhoods' => $topNeighborhoods,
            'registrations' => $registrations,
        ]);
    }
}
