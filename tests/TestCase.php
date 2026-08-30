<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    protected function setUp(): void
    {
        parent::setUp();

        // PricingSetting::current() y WhatsAppSetting::current() ahora usan
        // Cache::remember() (optimización de escala, pedido explícito del
        // usuario). El driver de test es "array" — vive en memoria durante
        // todo el proceso PHP, no por request — así que sin este forget, un
        // test que modifica la fila y el rollback de RefreshDatabase que le
        // sigue dejarían el valor viejo cacheado para el próximo test.
        Cache::forget('pricing_settings.current');
        Cache::forget('whatsapp_settings.current');
    }
}
