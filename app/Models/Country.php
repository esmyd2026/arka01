<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Catálogo de países (ver migración create_countries_table para el porqué
 * completo). Es una lista corta que casi nunca cambia y se consulta en
 * decenas de puntos por request (cálculo de precio, validación de teléfono,
 * geocodificación) — se cachea entera igual que PricingSetting, invalidando
 * con cualquier cambio real desde /admin/paises.
 */
class Country extends Model
{
    private const CACHE_KEY = 'countries.active';

    protected $fillable = [
        'name',
        'iso_code',
        'phone_prefix',
        'currency_code',
        'currency_symbol',
        'decimal_digits',
        'phone_local_regex',
        'phone_format_hint',
        'strips_leading_zero',
        'geocoding_region_code',
        'is_default',
        'is_active',
    ];

    protected $casts = [
        'decimal_digits' => 'integer',
        'strips_leading_zero' => 'boolean',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * @return Collection<int, self>
     */
    public static function active(): Collection
    {
        return Cache::remember(self::CACHE_KEY, now()->addHour(), fn () => self::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get());
    }

    /**
     * Fallback universal para cualquier lugar que necesite "un" país y no
     * tenga uno concreto (visitante sin sesión, dato viejo sin match). Si
     * nadie tiene is_default=true (no debería pasar, pero un admin podría
     * desactivar el único default por error), cae al primero activo para no
     * romper con un null en cascada.
     */
    public static function default(): self
    {
        return self::active()->firstWhere('is_default', true)
            ?? self::active()->first()
            ?? self::query()->firstOrFail();
    }

    /**
     * Empareja un teléfono completo (country_code+phone_local concatenado
     * SIN separador, ver User::phone) contra el prefijo de cada país
     * activo. Se ordena por prefijo más largo primero para que un futuro
     * '+1' no le gane por accidente a un '+123' más específico.
     */
    public static function forPhone(?string $phone): ?self
    {
        if (! $phone) {
            return null;
        }

        return self::active()
            ->sortByDesc(fn (self $country) => strlen($country->phone_prefix))
            ->first(fn (self $country) => str_starts_with($phone, $country->phone_prefix));
    }

    /**
     * Subconjunto seguro para mandar al frontend (Inertia/API móvil) — nunca
     * el modelo completo, para no filtrar columnas internas nuevas el día
     * que este catálogo crezca. Es todo dato de catálogo público (nada
     * sensible), así que se reusa tanto para "país del usuario logueado"
     * (HandleInertiaRequests) como para la lista de países del selector de
     * registro (Auth/Register.vue) — una sola forma, un solo lugar que la
     * arma.
     */
    public function publicPayload(): array
    {
        return [
            'name' => $this->name,
            'iso_code' => $this->iso_code,
            'phone_prefix' => $this->phone_prefix,
            'phone_local_regex' => $this->phone_local_regex,
            'phone_format_hint' => $this->phone_format_hint,
            'strips_leading_zero' => $this->strips_leading_zero,
            'is_default' => $this->is_default,
            'currency_code' => $this->currency_code,
            'currency_symbol' => $this->currency_symbol,
            'decimal_digits' => $this->decimal_digits,
            'geocoding_region_code' => $this->geocoding_region_code,
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::CACHE_KEY));
    }
}
