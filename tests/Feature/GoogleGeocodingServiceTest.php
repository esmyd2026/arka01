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
}
