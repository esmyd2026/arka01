<?php

namespace App\Services\Ride;

use App\Models\RideRequest;
use App\Models\User;
use App\Services\PriceCalculator;

/**
 * Compara el total ya confirmado por el cliente contra la tarifa estimada
 * del conductor que está viendo la oferta. El total confirmado nunca se
 * recalcula silenciosamente al avanzar por la cascada de despacho.
 */
class RideOfferComparison
{
    /**
     * @return array{locked_total: float, driver_estimated_total: float, difference: float, uses_another_driver_price: bool}
     */
    public function forDriver(RideRequest $rideRequest, User $driver): array
    {
        $rideRequest->loadMissing('stops');
        $profile = $driver->driverProfile;
        $rate = (float) ($profile?->rate_per_km ?? 0);
        $minimumFare = $profile?->minimum_fare !== null ? (float) $profile->minimum_fare : null;
        $at = $rideRequest->requested_at;

        $driverTotal = PriceCalculator::suggestedPrice(
            (float) $rideRequest->distance_km,
            $rate,
            $at,
            $minimumFare,
        )['total'];

        foreach ($rideRequest->stops as $stop) {
            $driverTotal += PriceCalculator::suggestedPrice(
                (float) ($stop->leg_distance_km ?? 0),
                $rate,
                $at,
                $minimumFare,
            )['total'];
        }

        $pickup = PriceCalculator::pickupSurchargeForDriver(
            $driver->id,
            (float) $rideRequest->origin_lat,
            (float) $rideRequest->origin_lng,
        );
        $driverTotal = PriceCalculator::roundUpToDime($driverTotal + (float) ($pickup['fare'] ?? 0));
        $lockedTotal = round(
            (float) $rideRequest->current_offered_price + (float) ($rideRequest->stops_price ?? 0),
            2,
        );

        return [
            'locked_total' => $lockedTotal,
            'driver_estimated_total' => $driverTotal,
            'difference' => round($lockedTotal - $driverTotal, 2),
            'uses_another_driver_price' => $rideRequest->price_reference_driver_user_id !== null
                && (int) $rideRequest->price_reference_driver_user_id !== (int) $driver->id,
        ];
    }
}
