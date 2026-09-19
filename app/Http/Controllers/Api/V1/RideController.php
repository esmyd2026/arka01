<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\RideResource;
use App\Models\Review;
use App\Models\Ride;
use App\Models\RideStop;
use App\Services\Ride\RideLifecycle;
use App\Services\Ride\RideMessageSender;
use App\Services\Ride\RideRescheduler;
use App\Services\Ride\RideReviewer;
use App\Services\Ride\RideStopCompleter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

/**
 * Ciclo de vida de una carrera ya aceptada, desde la app móvil
 * (ROADMAP_APLICACION_MOVIL_CAPACITOR.md, Hito 5). Reusa exactamente la
 * misma lógica que la web (App\Services\Ride\RideLifecycle y compañía,
 * extraídos de RideController/RideMessageController/ReviewController) —
 * mismos radios de tolerancia GPS, mismas listas de motivos, mismos
 * efectos posteriores.
 */
class RideController extends Controller
{
    public function __construct(
        private readonly RideLifecycle $rideLifecycle,
        private readonly RideStopCompleter $rideStopCompleter,
        private readonly RideRescheduler $rideRescheduler,
        private readonly RideMessageSender $rideMessageSender,
        private readonly RideReviewer $rideReviewer,
    ) {}

    /**
     * La carrera activa de este usuario (como cliente o como conductor),
     * si tiene una — pensado para que la app sepa a qué pantalla de
     * seguimiento volver al abrir, sin tener que guardar el id localmente.
     */
    public function active(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $ride = Ride::query()
            ->where(fn ($query) => $query->where('client_user_id', $userId)->orWhere('driver_user_id', $userId))
            ->whereIn('status', ['scheduled', 'in_progress'])
            ->with(['client', 'driver.driverProfile'])
            ->latest()
            ->first();

        return response()->json(['ride' => $ride ? new RideResource($ride) : null]);
    }

    public function show(Request $request, Ride $ride): JsonResponse
    {
        $userId = $request->user()->id;

        if ($ride->client_user_id !== $userId && $ride->driver_user_id !== $userId) {
            abort(403);
        }

        $ride->load(['client', 'driver.driverProfile']);

        return response()->json(['ride' => new RideResource($ride)]);
    }

    public function start(Request $request, Ride $ride): JsonResponse
    {
        $this->rideLifecycle->start($ride, $request->user());

        return response()->json(['ride' => new RideResource($ride->fresh(['client', 'driver.driverProfile']))]);
    }

    public function headingToPassenger(Request $request, Ride $ride): JsonResponse
    {
        $this->rideLifecycle->headingToPassenger($ride, $request->user());

        return response()->json(['ride' => new RideResource($ride->fresh(['client', 'driver.driverProfile']))]);
    }

    public function arrived(Request $request, Ride $ride): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $this->rideLifecycle->arrived(
            $ride,
            $request->user(),
            isset($validated['lat']) ? (float) $validated['lat'] : null,
            isset($validated['lng']) ? (float) $validated['lng'] : null,
        );

        return response()->json(['ride' => new RideResource($ride->fresh(['client', 'driver.driverProfile']))]);
    }

    public function pickedUp(Request $request, Ride $ride): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $this->rideLifecycle->pickedUp(
            $ride,
            $request->user(),
            isset($validated['lat']) ? (float) $validated['lat'] : null,
            isset($validated['lng']) ? (float) $validated['lng'] : null,
        );

        return response()->json(['ride' => new RideResource($ride->fresh(['client', 'driver.driverProfile']))]);
    }

    public function complete(Request $request, Ride $ride): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'completion_reason' => ['nullable', 'string', Rule::in(RideLifecycle::EARLY_COMPLETION_REASONS)],
            'completion_note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->rideLifecycle->complete(
            $ride,
            $request->user(),
            isset($validated['lat']) ? (float) $validated['lat'] : null,
            isset($validated['lng']) ? (float) $validated['lng'] : null,
            $validated['completion_reason'] ?? null,
            $validated['completion_note'] ?? null,
        );

        return response()->json(['ride' => new RideResource($ride->fresh(['client', 'driver.driverProfile']))]);
    }

    public function cancel(Request $request, Ride $ride): JsonResponse
    {
        $userId = $request->user()->id;
        $isDriver = $ride->driver_user_id === $userId;

        if (! $isDriver && $ride->client_user_id !== $userId) {
            abort(403);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', Rule::in($isDriver ? RideLifecycle::DRIVER_CANCEL_REASONS : RideLifecycle::CLIENT_CANCEL_REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->rideLifecycle->cancel($ride, $request->user(), $validated['reason'], $validated['note'] ?? null);

        return response()->json(['ride' => new RideResource($ride->fresh(['client', 'driver.driverProfile']))]);
    }

    public function updateLocation(Request $request, Ride $ride): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
        ]);

        $arrivedAt = $this->rideLifecycle->updateLocation(
            $ride,
            $request->user(),
            (float) $validated['lat'],
            (float) $validated['lng'],
        );

        return response()->json([
            'ok' => true,
            'arrived_at' => $arrivedAt?->toIso8601String(),
        ]);
    }

    /**
     * Completa una parada intermedia (carreras con paradas, roadmap Hito 5
     * — el móvil todavía no arma paradas al pedir, pero puede completarlas
     * si la carrera vino de la web con alguna).
     */
    public function completeStop(Request $request, Ride $ride, RideStop $stop): JsonResponse
    {
        $validated = $request->validate([
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
            'cancel_rest' => ['sometimes', 'boolean'],
        ]);

        $this->rideStopCompleter->complete(
            $ride,
            $stop,
            $request->user(),
            isset($validated['lat']) ? (float) $validated['lat'] : null,
            isset($validated['lng']) ? (float) $validated['lng'] : null,
            (bool) ($validated['cancel_rest'] ?? false),
        );

        return response()->json(['ride' => new RideResource($ride->fresh(['client', 'driver.driverProfile']))]);
    }

    /**
     * El cliente propone otro horario para una carrera programada ya
     * aceptada — queda pendiente hasta que el conductor confirme o rechace.
     */
    public function proposeReschedule(Request $request, Ride $ride): JsonResponse
    {
        $validated = $request->validate([
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'scheduled_time' => ['required', 'date_format:H:i'],
        ]);

        $this->rideRescheduler->propose($ride, $request->user(), $validated['scheduled_date'], $validated['scheduled_time']);

        return response()->json(['ride' => new RideResource($ride->fresh(['client', 'driver.driverProfile']))]);
    }

    public function confirmReschedule(Request $request, Ride $ride): JsonResponse
    {
        $this->rideRescheduler->confirm($ride, $request->user());

        return response()->json(['ride' => new RideResource($ride->fresh(['client', 'driver.driverProfile']))]);
    }

    public function rejectReschedule(Request $request, Ride $ride): JsonResponse
    {
        $this->rideRescheduler->reject($ride, $request->user());

        return response()->json(['ride' => new RideResource($ride->fresh(['client', 'driver.driverProfile']))]);
    }

    /**
     * Chat temporal cliente↔conductor — solo mientras la carrera está
     * programada o en curso (Ride::chatIsOpen()).
     */
    public function messages(Request $request, Ride $ride): JsonResponse
    {
        $userId = $request->user()->id;

        if ($ride->client_user_id !== $userId && $ride->driver_user_id !== $userId) {
            abort(403);
        }

        $messages = $ride->messages()->with('sender')->oldest()->get()->map(fn ($message) => [
            'id' => $message->id,
            'ride_id' => $message->ride_id,
            'sender_user_id' => $message->sender_user_id,
            'sender_name' => $message->sender->name,
            'body' => $message->body,
            'created_at' => $message->created_at->toIso8601String(),
        ]);

        return response()->json(['messages' => $messages]);
    }

    public function sendMessage(Request $request, Ride $ride): JsonResponse
    {
        $validated = $request->validate([
            'body' => ['required', 'string', 'max:500'],
        ]);

        $message = $this->rideMessageSender->send($ride, $request->user(), $validated['body']);

        return response()->json([
            'id' => $message->id,
            'ride_id' => $message->ride_id,
            'sender_user_id' => $message->sender_user_id,
            'sender_name' => $request->user()->name,
            'body' => $message->body,
            'created_at' => $message->created_at->toIso8601String(),
        ], 201);
    }

    /**
     * Calificación de cliente/conductor al finalizar la carrera — cada uno
     * una sola vez, de forma independiente (App\Services\Ride\RideReviewer).
     */
    public function review(Request $request, Ride $ride): JsonResponse
    {
        // El motivo obligatorio si se baja de las 5 estrellas lo valida
        // RideReviewer, no aquí — ver ReviewController (web) para el detalle
        // de por qué el orden importa.
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'rating_reason_id' => ['nullable', 'integer', 'exists:rating_reasons,id'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $review = $this->rideReviewer->review(
            $ride,
            $request->user(),
            $validated['rating'],
            $validated['rating_reason_id'] ?? null,
            $validated['comment'] ?? null,
        );

        return response()->json([
            'id' => $review->id,
            'rating' => $review->rating,
            'comment' => $review->comment,
        ], 201);
    }

    /**
     * Historial paginado de carreras del usuario (cliente o conductor) —
     * mismo criterio que `rides.index` en la web (paginado de a 10), sin
     * las listas de solicitudes pendientes/entrantes que ya tienen su
     * propio endpoint móvil.
     */
    public function history(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $rideHistory = Ride::query()
            ->where(fn ($query) => $query->where('client_user_id', $userId)->orWhere('driver_user_id', $userId))
            ->whereNotIn('status', ['in_progress', 'scheduled'])
            ->with(['client:id,name,avatar_path', 'driver:id,name,avatar_path'])
            ->latest()
            ->paginate(10);

        $myReviewedRideIds = Review::query()
            ->where('reviewer_user_id', $userId)
            ->whereIn('ride_id', $rideHistory->getCollection()->where('status', 'completed')->pluck('id'))
            ->pluck('ride_id');

        return response()->json([
            'rides' => $rideHistory->getCollection()->map(fn (Ride $ride) => [
                'id' => $ride->id,
                'client' => ['name' => $ride->client?->name ?? 'Cuenta eliminada', 'avatar_url' => $ride->client?->avatar_url],
                'driver' => ['name' => $ride->driver?->name ?? 'Cuenta eliminada', 'avatar_url' => $ride->driver?->avatar_url],
                'status' => $ride->status,
                'status_label' => match ($ride->status) {
                    'completed' => 'Completada',
                    'cancelled' => 'Cancelada',
                    default => ucfirst(str_replace('_', ' ', $ride->status)),
                },
                'origin_address' => $ride->origin_address,
                'destination_address' => $ride->destination_address,
                'distance_km' => $ride->distance_km !== null ? (float) $ride->distance_km : null,
                'payment_method' => $ride->payment_method,
                'price' => (float) $ride->price,
                'occurred_at' => ($ride->completed_at ?? $ride->cancelled_at ?? $ride->created_at)->toIso8601String(),
                'needs_my_review' => $ride->status === 'completed' && ! $myReviewedRideIds->contains($ride->id),
            ])->values(),
            'current_page' => $rideHistory->currentPage(),
            'last_page' => $rideHistory->lastPage(),
            'total' => $rideHistory->total(),
        ]);
    }

    /**
     * Enlace firmado de seguimiento en vivo (para compartir por WhatsApp,
     * etc.) — mismo mecanismo que RideController::trackingLink() (web).
     */
    public function trackingLink(Request $request, Ride $ride): JsonResponse
    {
        $userId = $request->user()->id;

        if ($ride->client_user_id !== $userId && $ride->driver_user_id !== $userId) {
            abort(403);
        }

        $url = URL::temporarySignedRoute('public.rides.track', now()->addHours(24), ['ride' => $ride->public_id]);

        return response()->json(['url' => $url]);
    }
}
