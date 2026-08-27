<?php

namespace App\Http\Controllers;

use App\Models\ClientCooperative;
use App\Models\Review;
use App\Models\Ride;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pedido explícito del usuario: "quiero ver mis clientes vinculados el
 * detalle no tanto pero al menos la lista, cantidad de carreras,
 * puntuaccion y desvincular también" — clientes que agregaron a esta
 * cooperativa a su red (App\Models\ClientCooperative), no los que pidieron
 * una carrera puntual.
 */
class CooperativeClientController extends Controller
{
    public function index(Request $request): Response
    {
        $cooperative = $request->user()->cooperative()->firstOrFail();

        $links = ClientCooperative::query()
            ->where('cooperative_id', $cooperative->id)
            ->with('client:id,name,avatar_path,member_code')
            ->latest()
            ->get();

        $clientIds = $links->pluck('client_user_id');

        // Mismo criterio que CooperativeDashboardController::index(): total
        // de carreras del cliente (no acotado a esta cooperativa puntual —
        // un cliente puede tener flota propia además de esta red) y su
        // calificación como pasajero.
        $rideStats = Ride::query()->whereIn('client_user_id', $clientIds)
            ->selectRaw("client_user_id, sum(case when status = 'completed' then 1 else 0 end) as completed_rides, sum(case when status = 'cancelled' then 1 else 0 end) as cancelled_rides")
            ->groupBy('client_user_id')->get()->keyBy('client_user_id');

        $reviewStats = Review::query()->whereIn('reviewee_user_id', $clientIds)
            ->selectRaw('reviewee_user_id, avg(rating) as average_rating, count(*) as review_count')
            ->groupBy('reviewee_user_id')->get()->keyBy('reviewee_user_id');

        return Inertia::render('Cooperative/Clients', [
            'cooperative' => $cooperative,
            'clients' => $links->map(function (ClientCooperative $link) use ($rideStats, $reviewStats) {
                $rides = $rideStats->get($link->client_user_id);
                $reviews = $reviewStats->get($link->client_user_id);

                return [
                    'link_id' => $link->id,
                    'user_id' => $link->client_user_id,
                    'name' => $link->client?->name ?? 'Cuenta eliminada',
                    'avatar_url' => $link->client?->avatar_url,
                    'member_code' => $link->client?->member_code,
                    'completed_rides' => (int) ($rides?->completed_rides ?? 0),
                    'cancelled_rides' => (int) ($rides?->cancelled_rides ?? 0),
                    'average_rating' => round((float) ($reviews?->average_rating ?? 0), 1),
                    'review_count' => (int) ($reviews?->review_count ?? 0),
                    'linked_at' => $link->created_at->toIso8601String(),
                ];
            }),
        ]);
    }

    /**
     * Desvincular (pedido explícito del usuario) — el cliente sigue
     * pudiendo pedirle carreras puntuales a la cooperativa si la vuelve a
     * agregar a su red; esto solo saca el vínculo actual, no borra nada de
     * su historial de carreras.
     */
    public function destroy(Request $request, ClientCooperative $clientCooperative): RedirectResponse
    {
        $cooperative = $request->user()->cooperative()->firstOrFail();
        abort_unless($clientCooperative->cooperative_id === $cooperative->id, 403);

        $clientCooperative->delete();

        return back()->with('status', 'Cliente desvinculado.');
    }
}
