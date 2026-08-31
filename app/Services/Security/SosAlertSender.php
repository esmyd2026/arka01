<?php

namespace App\Services\Security;

use App\Mail\SosAlertMail;
use App\Models\Ride;
use App\Models\SosAlert;
use App\Models\User;
use App\Services\SystemEventLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

/**
 * Botón SOS (sección 8): visible durante un viaje en curso, notifica a los
 * contactos de confianza de quien lo activa por correo y deja un registro
 * de la emergencia con ubicación y datos del conductor/vehículo —
 * extraído de SosAlertController (roadmap app móvil, "full backend": nunca
 * duplicar una regla de negocio entre web y móvil).
 */
class SosAlertSender
{
    /**
     * @return array{alert: SosAlert, notified: int}
     */
    public function trigger(Ride $ride, User $actingUser): array
    {
        $userId = $actingUser->id;

        if ($ride->client_user_id !== $userId && $ride->driver_user_id !== $userId) {
            abort(403);
        }

        if ($ride->status !== 'in_progress') {
            throw ValidationException::withMessages([
                'ride' => 'Esta carrera ya no está en curso.',
            ]);
        }

        $ride->loadMissing('driver.driverProfile');
        $driverProfile = $ride->driver->driverProfile;

        $alert = SosAlert::query()->create([
            'ride_id' => $ride->id,
            'triggered_by' => $userId,
            'driver_name' => $ride->driver->name,
            'driver_phone' => $ride->driver->phone,
            'vehicle_plate' => $driverProfile?->vehicle_plate,
            'vehicle_make' => $driverProfile?->vehicle_make,
            'vehicle_model' => $driverProfile?->vehicle_model,
            // Dónde está el vehículo en este momento, no dónde está quien
            // activa la alerta — es lo más útil para ubicar la emergencia.
            'lat' => $driverProfile?->current_lat,
            'lng' => $driverProfile?->current_lng,
        ]);

        $contacts = $actingUser->trustedContacts()->whereNotNull('email')->get();

        $trackingUrl = URL::temporarySignedRoute('public.rides.track', now()->addHours(48), ['ride' => $ride->public_id]);

        $notified = 0;
        foreach ($contacts as $contact) {
            try {
                Mail::to($contact->email)->send(new SosAlertMail($alert, $actingUser->name, $trackingUrl));
                $notified++;
            } catch (\Throwable $e) {
                // Un contacto con el correo mal escrito no debería tumbar el
                // resto de los avisos — se deja registro para revisar después.
                Log::warning('No se pudo enviar la alerta SOS a un contacto', [
                    'sos_alert_id' => $alert->id,
                    'contact_id' => $contact->id,
                    'error' => $e->getMessage(),
                ]);

                SystemEventLogger::log(
                    eventType: 'sos_contact_email_failed',
                    module: 'sos',
                    message: "No se pudo enviar la alerta SOS al contacto de confianza #{$contact->id}.",
                    severity: 'warning',
                    context: ['sos_alert_id' => $alert->id, 'error' => $e->getMessage()],
                    userId: $actingUser->id,
                    channel: 'email',
                );
            }
        }

        $alert->update(['notified_contacts_count' => $notified]);

        return ['alert' => $alert, 'notified' => $notified];
    }
}
