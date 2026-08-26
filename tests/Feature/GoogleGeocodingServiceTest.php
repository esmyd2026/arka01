<?php

namespace Tests\Feature;

use App\Services\GoogleGeocodingService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bug real reportado por el usuario (con captura de WhatsApp real):
 * "mandan ubicacion escrita y el bot no la entiende. ejemplo mandaron
 * coronel y cocalicuchima... o escribe iglesia msi" — la API de Geocoding
 * busca direcciones estructuradas, no esquinas sueltas ni nombres de
 * lugares. Places (findplacefromtext) es el respaldo para ese caso.
 */
class GoogleGeocodingServiceTest extends TestCase
{
    public function test_falls_back_to_places_when_geocoding_finds_nothing(): void
    {
        Config::set('services.google_maps.server_api_key', 'fake-key');
        Http::fake([
            'maps.googleapis.com/maps/api/geocode/*' => Http::response(['results' => []], 200),
            'maps.googleapis.com/maps/api/place/findplacefromtext/*' => Http::response([
                'candidates' => [[
                    'formatted_address' => 'Iglesia MSI, Guayaquil',
                    'geometry' => ['location' => ['lat' => -2.19, 'lng' => -79.89]],
                ]],
            ], 200),
        ]);

        $point = (new GoogleGeocodingService)->resolve('iglesia msi');

        $this->assertSame('Iglesia MSI, Guayaquil', $point['address']);
        $this->assertSame(-2.19, $point['lat']);
    }

    public function test_returns_null_when_neither_geocoding_nor_places_find_anything(): void
    {
        Config::set('services.google_maps.server_api_key', 'fake-key');
        Http::fake([
            'maps.googleapis.com/maps/api/geocode/*' => Http::response(['results' => []], 200),
            'maps.googleapis.com/maps/api/place/findplacefromtext/*' => Http::response(['candidates' => []], 200),
        ]);

        $this->assertNull((new GoogleGeocodingService)->resolve('asdkjaslkdjaslkd'));
    }

    /**
     * Pedido explícito del usuario ("las direcciones que mete el cliente
     * por descripciones aun el bot no las detecta") — el sesgo de Places
     * estaba fijo en Guayaquil sin importar dónde estuviera el cliente de
     * verdad; ahora usa el punto de referencia real que le pasa el llamador
     * (ver WhatsAppRideBookingHandler::resolvePoint()).
     */
    public function test_places_fallback_biases_the_search_toward_the_given_point(): void
    {
        Config::set('services.google_maps.server_api_key', 'fake-key');
        Http::fake([
            'maps.googleapis.com/maps/api/geocode/*' => Http::response(['results' => []], 200),
            'maps.googleapis.com/maps/api/place/findplacefromtext/*' => Http::response([
                'candidates' => [['formatted_address' => 'Parque La Carolina, Quito', 'geometry' => ['location' => ['lat' => -0.18, 'lng' => -78.48]]]],
            ], 200),
        ]);

        (new GoogleGeocodingService)->resolve('parque la carolina', -0.1807, -78.4678);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'findplacefromtext')
            && str_contains((string) $request->url(), 'circle%3A30000%40-0.1807%2C-78.4678'));
    }

    /**
     * Pedido explícito del usuario ("la ubicación que le llega al conductor
     * dice 'ubicación compartida'... pero no le dio el detalle") — un pin
     * suelto en el mapa de WhatsApp solo trae coordenadas, sin dirección;
     * geocoding inverso le pone una dirección real.
     */
    public function test_reverse_geocode_returns_a_real_address_for_bare_coordinates(): void
    {
        Config::set('services.google_maps.server_api_key', 'fake-key');
        Http::fake([
            'maps.googleapis.com/maps/api/geocode/*' => Http::response([
                'results' => [['formatted_address' => 'Av. 9 de Octubre, Guayaquil']],
            ], 200),
        ]);

        $address = (new GoogleGeocodingService)->reverseGeocode(-2.19, -79.89);

        $this->assertSame('Av. 9 de Octubre, Guayaquil', $address);
    }
}
