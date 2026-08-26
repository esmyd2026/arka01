<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class GoogleGeocodingService
{
    /**
     * Pedido explícito del usuario ("las direcciones que mete el cliente
     * por descripciones aun el bot no las detecta"): el sesgo de búsqueda
     * de Places (ver findPlace()) estaba fijo en Guayaquil — para alguien
     * en Quito, Cuenca o cualquier otra ciudad del catálogo, ese sesgo
     * jugaba en contra en vez de ayudar (Google prioriza resultados cerca
     * del círculo, aunque el texto describa un lugar real en otra ciudad).
     * $biasLat/$biasLng dejan que quien llama pase un punto de referencia
     * real — la ciudad registrada del cliente para el origen, o el origen
     * ya resuelto para el destino (ver WhatsAppRideBookingHandler::
     * resolvePoint()) — sin bias, cae al centro de Guayaquil de siempre.
     *
     * @return array{lat: float, lng: float, address: string}|null
     */
    public function resolve(string $input, ?float $biasLat = null, ?float $biasLng = null): ?array
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
        return $this->findPlace($input, $key, $biasLat, $biasLng);
    }

    /** @return array{lat: float, lng: float, address: string}|null */
    private function findPlace(string $input, string $key, ?float $biasLat, ?float $biasLng): ?array
    {
        $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/place/findplacefromtext/json', [
            'input' => $input,
            'inputtype' => 'textquery',
            'fields' => 'formatted_address,geometry',
            'language' => 'es',
            'locationbias' => $biasLat !== null && $biasLng !== null
                ? "circle:30000@{$biasLat},{$biasLng}"
                : 'circle:50000@-2.1894,-79.8891', // Sin punto de referencia: Guayaquil de siempre.
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

    /**
     * Pedido explícito del usuario ("la ubicación que le llega al conductor
     * dice 'ubicación compartida' porque la mandó el cliente desde el mapa
     * de WhatsApp pero no le dio el detalle al chofer") — cuando alguien
     * suelta un pin en el mapa de WhatsApp (a diferencia de buscar un lugar
     * con nombre), el mensaje trae solo lat/lng, sin `address` ni `name`.
     * Geocoding inverso rellena una dirección real a partir de esas
     * coordenadas, en vez de dejar el texto genérico "Ubicación compartida"
     * que no le sirve de nada al conductor.
     */
    public function reverseGeocode(float $lat, float $lng): ?string
    {
        $key = config('services.google_maps.server_api_key');
        if (! $key) {
            return null;
        }

        $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/geocode/json', [
            'latlng' => "{$lat},{$lng}",
            'key' => $key,
            'language' => 'es',
        ]);
        $result = $response->successful() ? $response->json('results.0') : null;

        return $result['formatted_address'] ?? null;
    }
}
