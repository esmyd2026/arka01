<?php

namespace App\Services\Ride;

use App\Models\RatingReason;
use App\Models\Review;
use App\Models\Ride;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Calificación de cliente/conductor al finalizar una carrera — extraído de
 * ReviewController::store() (roadmap app móvil, Hito 5: nunca duplicar una
 * regla de negocio entre web y móvil). Cada uno califica una sola vez, de
 * forma independiente; a quién se califica se deduce solo (la otra parte
 * de la misma carrera).
 */
class RideReviewer
{
    public function review(Ride $ride, User $reviewer, int $rating, ?int $ratingReasonId, ?string $comment): Review
    {
        $userId = $reviewer->id;

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

        $direction = $userId === $ride->client_user_id ? 'client_to_driver' : 'driver_to_client';

        if ($ratingReasonId !== null) {
            $reasonIsValid = RatingReason::query()
                ->whereKey($ratingReasonId)
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

        return Review::query()->create([
            'ride_id' => $ride->id,
            'reviewer_user_id' => $userId,
            'reviewee_user_id' => $revieweeId,
            'rating' => $rating,
            'rating_reason_id' => $ratingReasonId,
            'comment' => $comment,
        ]);
    }
}
