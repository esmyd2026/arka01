<?php

namespace App\Services\Express;

use App\Models\DriverProfile;
use App\Models\ExpressRoute;
use App\Models\Fleet;
use App\Models\FleetMember;
use App\Models\PricingSetting;
use App\Models\User;
use App\Services\Haversine;
use App\Services\PriceCalculator;
use Illuminate\Validation\ValidationException;

/**
 * Publicar/editar/pausar/reanudar/cancelar un Expreso — extraído de
 * ExpressRouteController (roadmap app móvil, "full backend": nunca duplicar
 * una regla de negocio entre web y móvil).
 */
class ExpressRoutePublisher
{
    /** Pedido explícito del usuario: "publicar un Expreso" es del lado cliente, el conductor no solicita servicios. */
    public const SINGLE_ROLE_MESSAGE = 'Los conductores no pueden publicar Expresos — cada cuenta es cliente o conductor, no ambas.';

    /**
     * Pedido explícito del usuario: el precio NO tiene que calzar con el
     * estimado, pero tampoco puede irse tan bajo que deje de convenirle a
     * cualquier conductor — 50% del estimado como piso real.
     */
    public const MINIMUM_PRICE_FACTOR = 0.5;

    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'origin_lat' => ['required', 'numeric', 'between:-90,90'],
            'origin_lng' => ['required', 'numeric', 'between:-180,180'],
            'origin_address' => ['nullable', 'string', 'max:255'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_address' => ['nullable', 'string', 'max:255'],
            'days_of_week' => ['required', 'array', 'min:1'],
            'days_of_week.*' => ['integer', 'between:0,6'],
            'departure_time' => ['required', 'date_format:H:i'],
            // Ida y vuelta (pedido explícito del usuario): además de la hora
            // de salida DEL origen, hace falta la hora de salida DEL destino.
            'is_round_trip' => ['boolean'],
            'return_time' => ['nullable', 'required_if:is_round_trip,true', 'date_format:H:i'],
            'offered_price' => ['required', 'numeric', 'min:0.01'],
            'conditions' => ['nullable', 'array'],
            'conditions.*' => ['string', 'max:150'],
            'share_enabled' => ['boolean'],
            'max_companions' => ['nullable', 'integer', 'min:1', 'max:6'],
        ];
    }

    public static function updateRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'departure_time' => ['required', 'date_format:H:i'],
            'is_round_trip' => ['boolean'],
            'return_time' => ['nullable', 'required_if:is_round_trip,true', 'date_format:H:i'],
            'days_of_week' => ['required', 'array', 'min:1'],
            'days_of_week.*' => ['integer', 'between:0,6'],
            'offered_price' => ['required', 'numeric', 'min:0.01'],
            'share_enabled' => ['boolean'],
            'max_companions' => ['nullable', 'integer', 'min:1', 'max:6'],
        ];
    }

    /**
     * Promedio de tarifa/km de los conductores activos en cualquiera de las
     * flotas del cliente — con reserva a nivel de plataforma si todavía no
     * tiene ningún conductor (cliente nuevo, el caso más común).
     */
    public function referenceRatePerKm(int $clientUserId): float
    {
        $fleetIds = Fleet::query()->where('owner_user_id', $clientUserId)->pluck('id');

        $rates = FleetMember::query()
            ->whereIn('fleet_id', $fleetIds)
            ->whereNull('left_at')
            ->with('driver.driverProfile')
            ->get()
            ->map(fn ($member) => (float) ($member->driver->driverProfile?->rate_per_km ?? 0))
            ->filter();

        if ($rates->isNotEmpty()) {
            return round($rates->avg(), 2);
        }

        $platformAverage = DriverProfile::query()->where('rate_per_km', '>', 0)->avg('rate_per_km');

        return $platformAverage ? round((float) $platformAverage, 2) : 0.0;
    }

    public function suggestedPrice(float $originLat, float $originLng, float $destinationLat, float $destinationLng, float $ratePerKm): float
    {
        $distanceKm = Haversine::distanceKm($originLat, $originLng, $destinationLat, $destinationLng);

        return PriceCalculator::suggestedPrice($distanceKm, $ratePerKm)['base'];
    }

    public function minimumFare(): float
    {
        return (float) PricingSetting::current()->minimum_fare;
    }

    public function create(User $client, array $validated): ExpressRoute
    {
        if ($client->isDriver()) {
            throw ValidationException::withMessages(['name' => self::SINGLE_ROLE_MESSAGE]);
        }

        $suggestedPrice = $this->suggestedPrice(
            $validated['origin_lat'],
            $validated['origin_lng'],
            $validated['destination_lat'],
            $validated['destination_lng'],
            $this->referenceRatePerKm($client->id),
        );
        $minimumPrice = round($suggestedPrice * self::MINIMUM_PRICE_FACTOR, 2);

        if ($validated['offered_price'] < $minimumPrice) {
            throw ValidationException::withMessages([
                'offered_price' => 'El precio no puede ser menor a $'.number_format($minimumPrice, 2).' (mitad del estimado de $'.number_format($suggestedPrice, 2).').',
            ]);
        }

        $route = ExpressRoute::query()->create([
            'client_user_id' => $client->id,
            'name' => $validated['name'],
            'origin_lat' => $validated['origin_lat'],
            'origin_lng' => $validated['origin_lng'],
            'origin_address' => $validated['origin_address'] ?? null,
            'destination_lat' => $validated['destination_lat'],
            'destination_lng' => $validated['destination_lng'],
            'destination_address' => $validated['destination_address'] ?? null,
            'days_of_week' => array_values(array_unique($validated['days_of_week'])),
            'departure_time' => $validated['departure_time'],
            'is_round_trip' => $validated['is_round_trip'] ?? false,
            'return_time' => ($validated['is_round_trip'] ?? false) ? $validated['return_time'] : null,
            'offered_price' => $validated['offered_price'],
            'status' => 'open',
            'share_enabled' => $validated['share_enabled'] ?? false,
            'max_companions' => $validated['max_companions'] ?? null,
        ]);

        foreach ($validated['conditions'] ?? [] as $description) {
            $route->conditions()->create(['description' => $description]);
        }

        return $route;
    }

    /**
     * Edita los datos de un Expreso, solo mientras está abierto a
     * postulaciones — origen/destino no se editan acá (se usan los que ya
     * tiene guardados para el mismo piso de precio que rige al publicarlo).
     */
    public function update(ExpressRoute $route, array $validated): void
    {
        if (! $route->isOpenForApplications()) {
            throw ValidationException::withMessages([
                'route' => 'Este Expreso ya no está abierto a postulaciones, no se puede editar.',
            ]);
        }

        $suggestedPrice = $this->suggestedPrice(
            (float) $route->origin_lat,
            (float) $route->origin_lng,
            (float) $route->destination_lat,
            (float) $route->destination_lng,
            $this->referenceRatePerKm($route->client_user_id),
        );
        $minimumPrice = round($suggestedPrice * self::MINIMUM_PRICE_FACTOR, 2);

        if ($validated['offered_price'] < $minimumPrice) {
            throw ValidationException::withMessages([
                'offered_price' => 'El precio no puede ser menor a $'.number_format($minimumPrice, 2).' (mitad del estimado de $'.number_format($suggestedPrice, 2).').',
            ]);
        }

        $route->update([
            'name' => $validated['name'],
            'departure_time' => $validated['departure_time'],
            'is_round_trip' => $validated['is_round_trip'] ?? false,
            'return_time' => ($validated['is_round_trip'] ?? false) ? $validated['return_time'] : null,
            'days_of_week' => array_values(array_unique($validated['days_of_week'])),
            'offered_price' => $validated['offered_price'],
            'share_enabled' => $validated['share_enabled'] ?? false,
            'max_companions' => $validated['max_companions'] ?? null,
        ]);
    }

    public function pause(ExpressRoute $route): void
    {
        $route->update(['status' => 'paused']);
    }

    public function resume(ExpressRoute $route): void
    {
        if (! $route->assigned_driver_user_id) {
            throw ValidationException::withMessages([
                'route' => 'Todavía no tiene un conductor asignado.',
            ]);
        }

        $route->update(['status' => 'active']);
    }

    public function cancel(ExpressRoute $route): void
    {
        $route->update(['status' => 'cancelled']);
    }
}
