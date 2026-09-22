<?php

namespace App\Http\Controllers;

use App\Events\RidePaymentUpdated;
use App\Models\Ride;
use App\Notifications\RidePaymentStatusNotification;
use App\Services\Ride\RidePaymentManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RidePaymentController extends Controller
{
    public function __construct(private readonly RidePaymentManager $paymentManager) {}

    /** El cliente adjunta o reemplaza un comprobante rechazado. */
    public function uploadProof(Request $request, Ride $ride): RedirectResponse
    {
        abort_unless($ride->client_user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'payment_proof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $this->paymentManager->uploadProof($ride, $validated['payment_proof']);

        return back()->with('status', 'Comprobante optimizado y enviado. La cooperativa revisará el pago.');
    }

    /** Archivo privado: solo cliente, cooperativa receptora y administración. */
    public function proof(Request $request, Ride $ride): StreamedResponse
    {
        $ride->loadMissing('rideRequest.cooperative');
        $isClient = $ride->client_user_id === $request->user()->id;
        $isCooperative = $ride->rideRequest?->cooperative?->user_id === $request->user()->id;
        abort_unless($isClient || $isCooperative || $request->user()->isAdmin(), 403);
        abort_if(blank($ride->payment_proof_path), 404);

        return Storage::disk('local')->response($ride->payment_proof_path);
    }

    /** La cooperativa verifica que el dinero ingresó en su cuenta. */
    public function confirmTransfer(Request $request, Ride $ride): RedirectResponse
    {
        $cooperative = $this->ownedCooperativeFor($request, $ride);

        if ($ride->payment_method !== 'transferencia' || $ride->payment_status !== 'proof_submitted') {
            throw ValidationException::withMessages(['payment' => 'Este pago no tiene un comprobante pendiente de confirmar.']);
        }

        $ride->forceFill([
            'payment_status' => 'confirmed',
            'payment_confirmed_at' => now(),
            'payment_confirmed_by_user_id' => $request->user()->id,
            'payment_rejected_at' => null,
            'payment_rejection_reason' => null,
        ])->save();

        RidePaymentUpdated::dispatch($ride->fresh());

        $message = $cooperative->name.' confirmó el pago por transferencia de la carrera #'.$ride->id.'.';
        $ride->driver->notify($this->statusNotification($ride, 'Pago confirmado', $message, 'ride_payment_confirmed'));
        $ride->client->notify($this->statusNotification($ride, 'Transferencia confirmada', $message, 'ride_payment_confirmed'));

        return back()->with('status', 'Pago confirmado. El conductor ya ve la carrera como pagada.');
    }

    /** La cooperativa rechaza un comprobante ilegible o que no coincide. */
    public function rejectTransfer(Request $request, Ride $ride): RedirectResponse
    {
        $cooperative = $this->ownedCooperativeFor($request, $ride);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        if ($ride->payment_method !== 'transferencia' || $ride->payment_status !== 'proof_submitted') {
            throw ValidationException::withMessages(['payment' => 'Este pago no tiene un comprobante pendiente de revisar.']);
        }

        $ride->forceFill([
            'payment_status' => 'rejected',
            'payment_rejected_at' => now(),
            'payment_rejection_reason' => $validated['reason'],
            'payment_confirmed_at' => null,
            'payment_confirmed_by_user_id' => null,
        ])->save();

        RidePaymentUpdated::dispatch($ride->fresh());

        $ride->client->notify($this->statusNotification(
            $ride,
            'Revise su comprobante',
            $cooperative->name.' rechazó el comprobante de la carrera #'.$ride->id.': '.$validated['reason'],
            'ride_payment_rejected',
        ));

        return back()->with('status', 'Comprobante rechazado. El cliente puede corregirlo y enviarlo nuevamente.');
    }

    /** En efectivo el conductor confirma que recibió el valor del cliente. */
    public function confirmCash(Request $request, Ride $ride): RedirectResponse
    {
        abort_unless($ride->driver_user_id === $request->user()->id, 403);

        $this->paymentManager->confirmCash($ride);

        return back()->with('status', 'Pago en efectivo confirmado. La cooperativa ya puede verlo.');
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

    private function ownedCooperativeFor(Request $request, Ride $ride)
    {
        $cooperative = $this->cooperativeFor($ride);
        abort_unless($cooperative->user_id === $request->user()->id, 403);

        return $cooperative;
    }

    private function statusNotification(Ride $ride, string $title, string $message, string $type): RidePaymentStatusNotification
    {
        return new RidePaymentStatusNotification($ride->id, $title, $message, route('rides.show', $ride), $type);
    }
}
