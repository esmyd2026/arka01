<?php

namespace App\Services;

use App\Models\Review;
use App\Models\Ride;
use App\Models\RideRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Motor de despacho explicable de Arka01.
 *
 * No decide de qué bolsa salen los conductores: recibe únicamente quienes ya
 * pasaron las reglas de flota/cooperativa/públicos, capacidad, cobertura y
 * disponibilidad. Su única responsabilidad es ordenar ese grupo.
 */
class SmartDispatchScorer
{
    public const VERSION = 'v1';

    /**
     * @param  Collection<int, User>  $drivers
     * @return Collection<int, User>
     */
    public static function rank(Collection $drivers, float $originLat, float $originLng): Collection
    {
        if (! config('smart_dispatch.enabled', true) || $drivers->count() < 2) {
            return self::byDistance($drivers, $originLat, $originLng);
        }

        try {
            $scores = self::scores($drivers, $originLat, $originLng);

            return $drivers
                ->sortBy(fn (User $driver) => [
                    -($scores[$driver->id]['score'] ?? 0),
                    $scores[$driver->id]['distance_km'] ?? PHP_FLOAT_MAX,
                    $driver->id,
                ])
                ->values();
        } catch (Throwable $exception) {
            // El despacho nunca debe caerse por una métrica secundaria. Se
            // registra el problema y se vuelve al comportamiento conocido.
            Log::warning('El motor inteligente falló; se usó cercanía como respaldo.', [
                'exception' => $exception->getMessage(),
            ]);

            return self::byDistance($drivers, $originLat, $originLng);
        }
    }

    /**
     * Fotografía auditable del orden elegido. Solo se llama al crear la
     * solicitud, no desde el navegador ni en cada actualización de ubicación.
     *
     * @param  array<int, int>  $orderedDriverIds
     * @return array<int, array<string, mixed>>
     */
    public static function snapshot(array $orderedDriverIds, float $originLat, float $originLng): array
    {
        if (! config('smart_dispatch.enabled', true) || empty($orderedDriverIds)) {
            return [];
        }

        $drivers = User::query()->with('driverProfile')->whereIn('id', $orderedDriverIds)->get();
        $scores = self::scores($drivers, $originLat, $originLng);

        return collect($orderedDriverIds)
            ->filter(fn (int $id) => isset($scores[$id]))
            ->map(fn (int $id, int $position) => [
                'driver_user_id' => $id,
                'position' => $position + 1,
                ...$scores[$id],
            ])
            ->values()
            ->all();
    }

    /** @param array<int, int> $orderedDriverIds */
    public static function safeSnapshot(array $orderedDriverIds, float $originLat, float $originLng): array
    {
        try {
            return self::snapshot($orderedDriverIds, $originLat, $originLng);
        } catch (Throwable $exception) {
            Log::warning('No se pudo generar la auditoría del despacho inteligente.', [
                'exception' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /** @return array<int, array<string, mixed>> */
    private static function scores(Collection $drivers, float $originLat, float $originLng): array
    {
        $ids = $drivers->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (empty($ids)) {
            return [];
        }

        $accepted = RideRequest::query()
            ->whereIn('accepted_by', $ids)
            ->where('status', 'accepted')
            ->selectRaw('accepted_by as driver_id, count(*) as total')
            ->groupBy('accepted_by')->pluck('total', 'driver_id');

        $rideStats = Ride::query()
            ->whereIn('driver_user_id', $ids)
            ->selectRaw("driver_user_id, count(*) as total, sum(case when status = 'cancelled' and cancelled_by = 'driver' then 1 else 0 end) as driver_cancelled, max(completed_at) as last_completed_at")
            ->groupBy('driver_user_id')->get()->keyBy('driver_user_id');

        $ratings = Review::query()
            ->whereIn('reviewee_user_id', $ids)
            ->selectRaw('reviewee_user_id as driver_id, avg(rating) as average, count(*) as total')
            ->groupBy('reviewee_user_id')->get()->keyBy('driver_id');

        $weights = config('smart_dispatch.weights');
        $minimumSamples = (int) config('smart_dispatch.minimum_history_samples', 3);
        $maximumDistance = max(1, (float) config('smart_dispatch.maximum_relevant_distance_km', 20));

        return $drivers->mapWithKeys(function (User $driver) use ($originLat, $originLng, $accepted, $rideStats, $ratings, $weights, $minimumSamples, $maximumDistance) {
            $profile = $driver->driverProfile;
            $distance = ($profile?->current_lat !== null && $profile?->current_lng !== null)
                ? Haversine::distanceKm($originLat, $originLng, (float) $profile->current_lat, (float) $profile->current_lng)
                : null;

            $acceptedCount = (int) ($accepted[$driver->id] ?? 0);
            $rejectedCount = (int) ($profile?->rides_rejected_count ?? 0);
            $responseSamples = $acceptedCount + $rejectedCount;
            $acceptanceRatio = $responseSamples >= $minimumSamples ? $acceptedCount / max(1, $responseSamples) : 0.5;

            $rating = $ratings->get($driver->id);
            $ratingRatio = $rating && (int) $rating->total >= $minimumSamples ? (float) $rating->average / 5 : 0.7;

            $rides = $rideStats->get($driver->id);
            $reliabilityRatio = $rides && (int) $rides->total >= $minimumSamples
                ? 1 - ((int) $rides->driver_cancelled / max(1, (int) $rides->total))
                : 0.8;

            $idleRatio = 1.0;
            if ($rides?->last_completed_at) {
                $idleRatio = min(1, now()->diffInMinutes($rides->last_completed_at) / (24 * 60));
            }

            $proximityRatio = $distance === null ? 0 : max(0, 1 - min($distance, $maximumDistance) / $maximumDistance);
            $parts = [
                'proximity' => round($proximityRatio * $weights['proximity'], 2),
                'acceptance' => round($acceptanceRatio * $weights['acceptance'], 2),
                'rating' => round($ratingRatio * $weights['rating'], 2),
                'reliability' => round($reliabilityRatio * $weights['reliability'], 2),
                'idle_time' => round($idleRatio * $weights['idle_time'], 2),
            ];

            return [$driver->id => [
                'score' => round(array_sum($parts), 2),
                'distance_km' => $distance === null ? null : round($distance, 2),
                'reason' => self::reason($parts, $distance),
                'components' => $parts,
                'history_samples' => $responseSamples,
            ]];
        })->all();
    }

    private static function reason(array $parts, ?float $distance): string
    {
        arsort($parts);
        $strongest = array_key_first($parts);

        return match ($strongest) {
            'proximity' => $distance === null ? 'Disponible para atender' : 'Cercano al punto de origen',
            'acceptance' => 'Alta probabilidad de aceptar',
            'rating' => 'Buena calificación de clientes',
            'reliability' => 'Buen historial de cumplimiento',
            'idle_time' => 'Lleva más tiempo esperando una carrera',
            default => 'Mejor opción disponible',
        };
    }

    private static function byDistance(Collection $drivers, float $originLat, float $originLng): Collection
    {
        return $drivers->sortBy(fn (User $driver) => $driver->driverProfile->current_lat !== null && $driver->driverProfile->current_lng !== null
            ? Haversine::distanceKm($originLat, $originLng, (float) $driver->driverProfile->current_lat, (float) $driver->driverProfile->current_lng)
            : PHP_FLOAT_MAX)->values();
    }
}
