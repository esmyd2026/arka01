<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\SystemEventLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * Pedido explícito del usuario: "ver de dónde se registran las personas, por
 * su ubicación" — además de la ciudad (resuelta al toque contra el catálogo
 * propio, ver RegisteredUserController::store()), se resuelve un nombre de
 * barrio/zona aproximado vía OpenStreetMap Nominatim (gratis, mismo criterio
 * que el resto de mapas/rutas del proyecto — sección 9.3, sin depender de un
 * proveedor pago). Va en cola y no bloquea el registro: es un dato
 * informativo para el panel admin, nunca algo de lo que dependa el alta de
 * la cuenta.
 *
 * Necesita un worker de verdad corriendo (`php artisan queue:work`), mismo
 * criterio que App\Jobs\ExpireRideOffer.
 */
class ResolveRegistrationNeighborhood implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $userId,
        public readonly float $lat,
        public readonly float $lng,
    ) {}

    public function handle(): void
    {
        try {
            // User-Agent identificable: la política de uso de Nominatim lo
            // exige (https://operations.osmfoundation.org/policies/nominatim/),
            // sin esto puede bloquear las peticiones.
            $response = Http::withHeaders(['User-Agent' => 'Arka01/1.0 (contacto@arka01.com)'])
                ->timeout(5)
                ->get('https://nominatim.openstreetmap.org/reverse', [
                    'format' => 'jsonv2',
                    'lat' => $this->lat,
                    'lon' => $this->lng,
                    'zoom' => 16,
                    'addressdetails' => 1,
                ]);

            if (! $response->successful()) {
                SystemEventLogger::log(
                    eventType: 'reverse_geocode_failed',
                    module: 'registration',
                    message: 'No se pudo resolver el barrio de registro (Nominatim respondió con error).',
                    context: ['status' => $response->status()],
                    userId: $this->userId,
                );

                return;
            }

            $address = $response->json('address', []);
            $neighborhood = $address['suburb']
                ?? $address['neighbourhood']
                ?? $address['quarter']
                ?? $address['city_district']
                ?? null;

            if ($neighborhood) {
                User::whereKey($this->userId)->update(['registration_neighborhood' => $neighborhood]);
            }
        } catch (\Throwable $e) {
            SystemEventLogger::log(
                eventType: 'reverse_geocode_failed',
                module: 'registration',
                message: 'No se pudo resolver el barrio de registro.',
                context: ['error' => $e->getMessage()],
                userId: $this->userId,
            );
        }
    }
}
