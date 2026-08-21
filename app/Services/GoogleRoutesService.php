<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class GoogleRoutesService
{
    /**
     * Calcula una ruta con Google y la guarda brevemente. Redondear el origen
     * a 4 decimales agrupa el ruido normal del GPS (~11 m) y evita facturar
     * una ruta nueva por cada pequeña variación del teléfono.
     *
     * @return array{encoded_polyline:string,distance_km:float|null,duration_min:float|null}|null
     */
    public function route(float $originLat, float $originLng, float $destinationLat, float $destinationLng): ?array
    {
        $key = (string) config('services.google_maps.server_api_key');
        if ($key === '') return null;

        $coordinates = array_map(fn (float $value) => round($value, 4), [
            $originLat, $originLng, $destinationLat, $destinationLng,
        ]);
        $cacheKey = 'google-route:'.implode(':', $coordinates);

        return Cache::remember($cacheKey, now()->addSeconds(60), function () use ($key, $coordinates) {
            [$oLat, $oLng, $dLat, $dLng] = $coordinates;
            $response = Http::timeout(8)
                ->retry(1, 150)
                ->withHeaders([
                    'X-Goog-Api-Key' => $key,
                    'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.polyline.encodedPolyline',
                ])
                ->post('https://routes.googleapis.com/directions/v2:computeRoutes', [
                    'origin' => ['location' => ['latLng' => ['latitude' => $oLat, 'longitude' => $oLng]]],
                    'destination' => ['location' => ['latLng' => ['latitude' => $dLat, 'longitude' => $dLng]]],
                    'travelMode' => 'DRIVE',
                    'routingPreference' => 'TRAFFIC_AWARE',
                    'polylineQuality' => 'OVERVIEW',
                ]);

            if (! $response->successful() || ! $response->json('routes.0.polyline.encodedPolyline')) return null;

            $duration = (string) $response->json('routes.0.duration', '');

            return [
                'encoded_polyline' => $response->json('routes.0.polyline.encodedPolyline'),
                'distance_km' => is_numeric($response->json('routes.0.distanceMeters'))
                    ? round(((float) $response->json('routes.0.distanceMeters')) / 1000, 3)
                    : null,
                'duration_min' => preg_match('/^([0-9.]+)s$/', $duration, $matches)
                    ? round(((float) $matches[1]) / 60, 2)
                    : null,
            ];
        });
    }
}
