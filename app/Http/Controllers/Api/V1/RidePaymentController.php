<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\RideResource;
use App\Models\Ride;
use App\Services\Ride\RidePaymentManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pago de una carrera desde la app móvil (chat/radio/pago, pedido explícito
 * del usuario: "cierra todo el backend de pedir carrera"). Reusa
 * App\Services\Ride\RidePaymentManager, la misma lógica que
 * RidePaymentController (web) — confirmar/rechazar transferencia son
 * acciones de la cooperativa (panel web /cooperativa), no de esta app; acá
 * solo lo que le toca al cliente (adjuntar comprobante) y al conductor
 * (confirmar efectivo recibido).
 */
class RidePaymentController extends Controller
{
    public function __construct(private readonly RidePaymentManager $paymentManager) {}

    public function uploadProof(Request $request, Ride $ride): JsonResponse
    {
        abort_unless($ride->client_user_id === $request->user()->id, 403);

        $validated = $request->validate([
            'payment_proof' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $ride = $this->paymentManager->uploadProof($ride, $validated['payment_proof']);

        return response()->json(['ride' => new RideResource($ride->load(['client', 'driver.driverProfile']))]);
    }

    public function confirmCash(Request $request, Ride $ride): JsonResponse
    {
        abort_unless($ride->driver_user_id === $request->user()->id, 403);

        $ride = $this->paymentManager->confirmCash($ride);

        return response()->json(['ride' => new RideResource($ride->load(['client', 'driver.driverProfile']))]);
    }

    /**
     * Archivo privado del comprobante — mismo criterio de acceso que
     * RidePaymentController::proof() (web), pero autenticado por token en
     * vez de sesión: la URL que RideResource arma para el móvil apunta acá,
     * no a la ruta web (que exige sesión y por lo tanto nunca funcionaría
     * desde la app).
     */
    public function proof(Request $request, Ride $ride): StreamedResponse
    {
        $ride->loadMissing('rideRequest.cooperative');
        $isClient = $ride->client_user_id === $request->user()->id;
        $isCooperative = $ride->rideRequest?->cooperative?->user_id === $request->user()->id;
        abort_unless($isClient || $isCooperative, 403);
        abort_if(blank($ride->payment_proof_path), 404);

        return Storage::disk('local')->response($ride->payment_proof_path);
    }
}
