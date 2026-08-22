<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GoogleGeocodingService
{
    /** @return array{lat: float, lng: float, address: string}|null */
    public function resolve(string $input): ?array
    {
        $input = trim($input);
        if (preg_match('/^\s*(-?\d{1,2}(?:\.\d+)?)\s*[,;]\s*(-?\d{1,3}(?:\.\d+)?)\s*$/', $input, $matches)) {
            return ['lat' => (float) $matches[1], 'lng' => (float) $matches[2], 'address' => $input];
        }

        $key = config('services.google_maps.server_api_key');
        if (! $key || mb_strlen($input) < 5) {
            return null;
        }

        $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', [
            'address' => $input,
            'key' => $key,
            'language' => 'es',
            'region' => 'ec',
        ]);
        $result = $response->successful() ? $response->json('results.0') : null;
        $location = $result['geometry']['location'] ?? null;

        return $location ? [
            'lat' => (float) $location['lat'],
            'lng' => (float) $location['lng'],
            'address' => (string) ($result['formatted_address'] ?? $input),
        ] : null;
    }
}
