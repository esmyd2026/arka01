<?php

namespace App\Services\Trust;

use App\Models\Review;
use App\Models\Ride;
use App\Models\TrustCircleConnection;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Índice social explicable de Arka01.
 *
 * No pretende certificar que una persona sea "segura" ni reemplaza las
 * verificaciones de identidad. Resume señales que ya existen dentro de la
 * plataforma y siempre devuelve sus componentes para que nadie reciba una
 * puntuación opaca.
 */
class TrustIndexCalculator
{
    public function calculate(User $subject, ?User $viewer = null): array
    {
        $isDriver = $subject->isDriver();
        $roleColumn = $isDriver ? 'driver_user_id' : 'client_user_id';

        $rideCounts = Ride::query()
            ->where($roleColumn, $subject->id)
            ->whereIn('status', ['completed', 'cancelled'])
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
            ->first();

        $completed = (int) ($rideCounts?->completed ?? 0);
        $total = (int) ($rideCounts?->total ?? 0);

        $reviewStats = Review::query()
            ->where('reviewee_user_id', $subject->id)
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('AVG(rating) as average')
            ->first();

        $reviews = (int) ($reviewStats?->total ?? 0);
        $average = $reviews > 0 ? round((float) $reviewStats->average, 1) : null;

        // Promedio bayesiano: una sola calificación de 5 no pesa igual que
        // veinte. La referencia neutral inicial es 4/5 con cinco opiniones.
        $weightedRating = (($average ?? 4.0) * $reviews + 4.0 * 5) / ($reviews + 5);
        $reputationPoints = round(($weightedRating / 5) * 40);
        $experiencePoints = round(min($completed / 20, 1) * 25);
        $reliabilityPoints = $total > 0 ? round(($completed / $total) * 20) : 10;

        $mutualPeople = $viewer ? $this->mutualUserIds($subject, $viewer)->count() : 0;
        $networkPoints = round(min($mutualPeople / 5, 1) * 15);
        $score = max(0, min(100, $reputationPoints + $experiencePoints + $reliabilityPoints + $networkPoints));

        return [
            'score' => $score,
            'level' => match (true) {
                $score >= 85 => 'Muy alta',
                $score >= 70 => 'Alta',
                $score >= 50 => 'En crecimiento',
                default => 'Nueva',
            },
            'role' => $isDriver ? 'Conductor' : 'Cliente',
            'rating' => $average,
            'reviews_count' => $reviews,
            'completed_rides' => $completed,
            'mutual_people' => $mutualPeople,
            'components' => [
                ['key' => 'reputation', 'label' => 'Reputación', 'points' => $reputationPoints, 'maximum' => 40],
                ['key' => 'experience', 'label' => 'Experiencia', 'points' => $experiencePoints, 'maximum' => 25],
                ['key' => 'reliability', 'label' => 'Viajes completados', 'points' => $reliabilityPoints, 'maximum' => 20],
                ['key' => 'network', 'label' => 'Personas en común', 'points' => $networkPoints, 'maximum' => 15],
            ],
        ];
    }

    public function acceptedUserIds(User|int $user): Collection
    {
        $userId = $user instanceof User ? $user->id : $user;

        return TrustCircleConnection::query()
            ->where('status', 'accepted')
            ->where(fn ($query) => $query
                ->where('requester_user_id', $userId)
                ->orWhere('addressee_user_id', $userId))
            ->get(['requester_user_id', 'addressee_user_id'])
            ->toBase()
            ->map(fn (TrustCircleConnection $connection) => $connection->requester_user_id === $userId
                ? $connection->addressee_user_id
                : $connection->requester_user_id)
            ->unique()
            ->values();
    }

    public function mutualUserIds(User $first, User $second): Collection
    {
        return $this->acceptedUserIds($first)->intersect($this->acceptedUserIds($second))->values();
    }
}
