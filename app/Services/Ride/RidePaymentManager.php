<?php

namespace App\Services\Ride;

use App\Events\RidePaymentUpdated;
use App\Models\Ride;
use App\Notifications\RidePaymentStatusNotification;
use App\Services\PrivateImageOptimizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Pago de una carrera (efectivo/transferencia), extraído de
 * RidePaymentController (web) para que la app móvil use exactamente las
 * mismas reglas al confirmar efectivo o adjuntar un comprobante — mismo
 * criterio que el resto de App\Services\Ride\* (nunca duplicar una regla de
 * negocio entre web y móvil). Solo las dos acciones que le tocan al
 * cliente/conductor: confirmar/rechazar transferencia son de la cooperativa
 * (panel web /cooperativa), no de esta app.
 */
class RidePaymentManager
{
    public function __construct(private readonly PrivateImageOptimizer $imageOptimizer) {}

    /** El cliente adjunta (o reemplaza) el comprobante de una transferencia. */
    public function uploadProof(Ride $ride, UploadedFile $file): Ride
    {
        $cooperative = $this->cooperativeFor($ride);

        if ($ride->payment_method !== 'transferencia' || $ride->status !== 'completed') {
            throw ValidationException::withMessages([
                'payment_proof' => 'El comprobante se puede adjuntar al finalizar una carrera pagada por transferencia.',
            ]);
        }

        if (! in_array($ride->payment_status, ['pending', 'rejected'], true)) {
            throw ValidationException::withMessages([
                'payment_proof' => 'Este pago ya está en revisión o fue confirmado.',
            ]);
        }

        $stored = $this->imageOptimizer->store($file, 'ride-payment-proofs', 'ride-'.$ride->public_id);
        $previousPath = $ride->payment_proof_path;

        $ride->forceFill([
            'payment_status' => 'proof_submitted',
            'payment_proof_path' => $stored['path'],
            'payment_proof_mime' => $stored['mime'],
            'payment_proof_original_size' => $stored['original_size'],
            'payment_proof_stored_size' => $stored['stored_size'],
            'payment_proof_uploaded_at' => now(),
            'transfer_payment_notified_at' => now(),
            'payment_rejected_at' => null,
            'payment_rejection_reason' => null,
        ])->save();

        RidePaymentUpdated::dispatch($ride->fresh());

        if ($previousPath && $previousPath !== $stored['path']) {
            Storage::disk('local')->delete($previousPath);
        }

        $cooperative->user->notify(new RidePaymentStatusNotification(
            $ride->id,
            'Nuevo comprobante de carrera',
            $ride->client->name.' adjuntó el comprobante de $'.number_format($ride->chargedTotal(), 2).' para la carrera #'.$ride->id.'.',
            route('cooperative.wallet'),
            'ride_payment_proof_submitted',
        ));

        return $ride->fresh();
    }

    /** En efectivo el conductor confirma que recibió el valor del cliente. */
    public function confirmCash(Ride $ride): Ride
    {
        $cooperative = $this->cooperativeFor($ride);

        if ($ride->payment_method !== 'efectivo' || $ride->status !== 'completed' || $ride->payment_status !== 'pending') {
            throw ValidationException::withMessages(['payment' => 'Este pago en efectivo no está pendiente de confirmación.']);
        }

        $ride->forceFill([
            'payment_status' => 'confirmed',
            'payment_confirmed_at' => now(),
            'payment_confirmed_by_user_id' => $ride->driver_user_id,
        ])->save();

        RidePaymentUpdated::dispatch($ride->fresh());

        $message = $ride->driver->name.' confirmó que recibió $'.number_format($ride->chargedTotal(), 2).' en efectivo por la carrera #'.$ride->id.'.';
        $cooperative->user->notify(new RidePaymentStatusNotification(
            $ride->id,
            'Efectivo recibido por el conductor',
            $message,
            route('cooperative.wallet'),
            'ride_cash_payment_confirmed',
        ));
        $ride->client->notify(new RidePaymentStatusNotification($ride->id, 'Pago confirmado', $message, route('rides.show', $ride), 'ride_payment_confirmed'));

        return $ride->fresh();
    }

    /**
     * Cuenta(s) a la que el cliente debe transferir: la de la cooperativa si
     * la carrera vino por una, si no la personal del conductor. Solo tiene
     * sentido devolverla al CLIENTE y en pago por transferencia — mismo
     * criterio y mismos campos que RideController::show() (web).
     */
    public function transferAccountsFor(Ride $ride, int $viewerUserId): array
    {
        if ($ride->payment_method !== 'transferencia' || $viewerUserId !== $ride->client_user_id) {
            return [];
        }

        $ride->loadMissing(['rideRequest.cooperative', 'driver']);

        $accounts = $ride->rideRequest?->cooperative
            ? $ride->rideRequest->cooperative->bankAccounts
            : $ride->driver->bankAccounts()->get();

        return $accounts->map(fn ($account) => [
            'id' => $account->id,
            'account_holder_name' => $account->account_holder_name
                ?: ($ride->rideRequest?->cooperative?->legal_name ?? $ride->rideRequest?->cooperative?->name ?? $ride->driver->full_name),
            'bank_name' => $account->bank_name,
            'account_type' => $account->account_type,
            'account_number' => $account->account_number,
            'identity_number' => $account->identity_number,
            'is_favorite' => $account->is_favorite,
        ])->all();
    }

    private function cooperativeFor(Ride $ride)
    {
        $ride->loadMissing(['rideRequest.cooperative.user', 'client', 'driver']);
        $cooperative = $ride->rideRequest?->cooperative;

        if (! $cooperative) {
            throw ValidationException::withMessages(['payment' => 'Esta carrera no pertenece a una cooperativa.']);
        }

        return $cooperative;
    }
}
