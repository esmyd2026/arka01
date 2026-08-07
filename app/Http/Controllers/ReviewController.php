<?php

namespace App\Http\Controllers;

use App\Models\RatingReason;
use App\Models\Review;
use App\Models\Ride;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ReviewController extends Controller
{
    /**
     * Cliente y conductor se califican al finalizar la carrera (sección 3.6).
     * Cada uno puede calificar una sola vez; a quién califica ("reviewee")
     * se deduce solo, es siempre la otra parte de la misma carrera.
     *
     * Pedido explícito del usuario: la calificación es obligatoria, pero
     * cliente y conductor califican de forma INDEPENDIENTE — ninguno espera
     * al otro (se probó primero con un orden fijo cliente→conductor y se
     * revirtió a pedido del usuario). La calificación arranca en 5 estrellas
     * por defecto; si se baja, hay que elegir un motivo del catálogo
     * administrado (App\Models\RatingReason), distinto según quién califica
     * a quién. Si alguna de las dos partes no calificó todavía, se le
     * recuerda con un aviso en /carreras (ver RideController::index()).
     */
    public function store(Request $request, Ride $ride): RedirectResponse
    {
        $userId = $request->user()->id;

        if ($ride->client_user_id !== $userId && $ride->driver_user_id !== $userId) {
            abort(403);
        }

        if ($ride->status !== 'completed') {
            throw ValidationException::withMessages([
                'ride' => 'Todavía no se puede calificar: la carrera no está completada.',
            ]);
        }

        $alreadyReviewed = Review::query()
            ->where('ride_id', $ride->id)
            ->where('reviewer_user_id', $userId)
            ->exists();

        if ($alreadyReviewed) {
            throw ValidationException::withMessages([
                'ride' => 'Ya calificaste esta carrera.',
            ]);
        }

        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            // Motivo obligatorio solo si se baja de las 5 estrellas por
            // defecto (pedido explícito del usuario).
            'rating_reason_id' => ['required_if:rating,1,2,3,4', 'nullable', 'integer', 'exists:rating_reasons,id'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $direction = $userId === $ride->client_user_id ? 'client_to_driver' : 'driver_to_client';

        if (isset($validated['rating_reason_id'])) {
            $reasonIsValid = RatingReason::query()
                ->whereKey($validated['rating_reason_id'])
                ->where('direction', $direction)
                ->where('is_active', true)
                ->exists();

            if (! $reasonIsValid) {
                throw ValidationException::withMessages([
                    'rating_reason_id' => 'Ese motivo no es válido para esta calificación.',
                ]);
            }
        }

        $revieweeId = $ride->client_user_id === $userId ? $ride->driver_user_id : $ride->client_user_id;

        Review::query()->create([
            'ride_id' => $ride->id,
            'reviewer_user_id' => $userId,
            'reviewee_user_id' => $revieweeId,
            'rating' => $validated['rating'],
            'rating_reason_id' => $validated['rating_reason_id'] ?? null,
            'comment' => $validated['comment'] ?? null,
        ]);

        return back();
    }
}
