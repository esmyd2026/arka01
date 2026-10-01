<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Una fila POR PAÍS con los parámetros del cálculo de precio sugerido
 * (sección 5). Antes era una tabla singleton (una sola fila para toda la
 * plataforma) — bug reportado por el usuario: un tope de tarifa mínima
 * puesto en dólares para Ecuador le rompía el registro a un conductor que
 * cobra en pesos chilenos. Ahora cada país tiene su propia fila,
 * administrable desde /admin/tarifas/{pais} (ver Admin\CountriesController,
 * que crea la fila con valores por defecto apenas se da de alta un país
 * nuevo — nunca queda uno sin tarifas configuradas).
 *
 * Optimización de escala (pedido explícito del usuario: "anticiparme a que
 * esto no suceda cuando comience a crecer la demanda"): se consulta decenas
 * de veces por request bajo carga real (cada cálculo de precio, cada parada
 * de una carrera con paradas) — todas leyendo filas que casi nunca cambian.
 * Se cachea una key por país; el hook de abajo invalida SOLO la key del país
 * que se guardó, así que nunca queda una lectura vieja después de tocar
 * /admin/tarifas/{pais}, sin tener que tirar el cache de países que nadie tocó.
 */
class PricingSetting extends Model
{
    private const CACHE_PREFIX = 'pricing_settings.';

    protected $fillable = [
        'country_id',
        'night_surcharge_percent',
        'night_starts_at',
        'night_ends_at',
        // Recargo de hora pico (pedido explícito del usuario): dos franjas
        // por día (mañana y tarde) — nunca se suma con el nocturno, ver
        // App\Services\PriceCalculator::suggestedPrice().
        'peak_surcharge_percent',
        'peak_morning_starts_at',
        'peak_morning_ends_at',
        'peak_evening_starts_at',
        'peak_evening_ends_at',
        // Cargo por trayecto de recogida (pedido explícito del usuario): el
        // conductor recorre esta distancia (Haversine, su ubicación actual
        // hasta el origen del cliente) sin pasajero — bajo el umbral se
        // sigue usando el colchón fijo de 0.8 km ya existente, sobre el
        // umbral se cobra aparte. Ver App\Services\PriceCalculator::pickupSurcharge().
        'pickup_surcharge_threshold_km',
        'pickup_surcharge_percent',
        'minimum_fare',
        // Ticket promedio por carrera (pedido explícito del usuario): valor
        // global que alimenta la proyección de ganancia mensual mostrada en
        // el catálogo de planes de conductor (ver SubscriptionPlan y
        // MyPlanController::attachEarningsProjection()).
        'average_ticket_price',
    ];

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    /**
     * @deprecated Wrapper de compatibilidad mientras se migran los
     * callsites que todavía no reciben un Country explícito — siempre
     * devuelve la fila del país predeterminado (Ecuador). Usar
     * forCountry() en código nuevo.
     */
    public static function current(): self
    {
        return self::forCountry(Country::default());
    }

    public static function forCountry(Country $country): self
    {
        return Cache::remember(self::CACHE_PREFIX.$country->id, now()->addHour(), fn () => self::query()
            ->where('country_id', $country->id)
            ->firstOrFail());
    }

    protected static function booted(): void
    {
        // Invalida el cache de arriba con CUALQUIER cambio real — sin
        // importar si vino de Admin\PricingSettingController::update() o de
        // un ->update() directo (ej. en tests) — nunca queda una lectura
        // vieja después de tocar /admin/tarifas/{pais}.
        static::saved(fn (self $setting) => Cache::forget(self::CACHE_PREFIX.$setting->country_id));
        static::deleted(fn (self $setting) => Cache::forget(self::CACHE_PREFIX.$setting->country_id));
    }
}
