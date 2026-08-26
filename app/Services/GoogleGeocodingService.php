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

        if ($location) {
            return [
                'lat' => (float) $location['lat'],
                'lng' => (float) $location['lng'],
                'address' => (string) ($result['formatted_address'] ?? $input),
            ];
        }

        // Bug real reportado por el usuario (con captura de WhatsApp real:
        // "mandan ubicacion escrita y el bot no la entiende. ejemplo
        // mandaron coronel y cocalicuchima... o escribe iglesia msi"): la
        // API de Geocoding busca DIRECCIONES estructuradas — una esquina
        // ("Coronel y Calicuchima") o el nombre de un lugar ("Iglesia MSI")
        // es justo lo que la de Places SÍ resuelve bien, es para texto
        // libre/lugares. Respaldo, no reemplazo: se prueba solo cuando
        // Geocoding no encontró nada.
        return $this->findPlace($input, $key);
    }

    /** @return array{lat: float, lng: float, address: string}|null */
    private function findPlace(string $input, string $key): ?array
    {
        $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/place/findplacefromtext/json', [
            'input' => $input,
            'inputtype' => 'textquery',
            'fields' => 'formatted_address,geometry',
            'language' => 'es',
            'locationbias' => 'circle:50000@-2.1894,-79.8891', // Guayaquil — mismo criterio que 'region: ec' de Geocoding.
            'key' => $key,
        ]);
        $result = $response->successful() ? $response->json('candidates.0') : null;
        $location = $result['geometry']['location'] ?? null;

        return $location ? [
            'lat' => (float) $location['lat'],
            'lng' => (float) $location['lng'],
            'address' => (string) ($result['formatted_address'] ?? $input),
        ] : null;
    }
}
