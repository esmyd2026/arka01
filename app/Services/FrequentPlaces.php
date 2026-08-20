<?php

namespace App\Services;

use App\Models\RideRequest;

/**
 * Direcciones que un cliente ya usó antes, sean de origen o destino (sección
 * 4 del documento de rediseño UX: "destinos recientes" en Inicio) — pedido
 * explícito del usuario ("guardá las que ya ha realizado para que aparezcan
 * como favoritas"). Extraído de RideRequestController::frequentPlacesFor()
 * para reusarlo también desde DashboardController, sin duplicar la consulta.
 */
class FrequentPlaces
{
    /**
     * @return array<int, array{address: string, lat: float, lng: float, sector_id: int|null}>
     */
    public static function forClient(int $clientUserId): array
    {
        // Últimas 50 solicitudes alcanzan de sobra para detectar lugares
        // frecuentes sin escanear el historial completo de un cliente muy
        // activo — no hace falta más para esto.
        $recent = RideRequest::query()
            ->where('client_user_id', $clientUserId)
            ->latest()
            ->limit(50)
            ->get(['origin_address', 'origin_lat', 'origin_lng', 'origin_sector_id', 'destination_address', 'destination_lat', 'destination_lng', 'destination_sector_id']);

        $places = collect();

        foreach ($recent as $r) {
            if (filled($r->origin_address)) {
                $places->push(['address' => $r->origin_address, 'lat' => (float) $r->origin_lat, 'lng' => (float) $r->origin_lng, 'sector_id' => $r->origin_sector_id]);
            }

            if (filled($r->destination_address)) {
                $places->push(['address' => $r->destination_address, 'lat' => (float) $r->destination_lat, 'lng' => (float) $r->destination_lng, 'sector_id' => $r->destination_sector_id]);
            }
        }

        return $places
            ->groupBy('address')
            ->map(fn ($group) => array_merge($group->first(), ['count' => $group->count()]))
            ->sortByDesc('count')
            ->take(6)
            ->values()
            ->all();
    }
}
