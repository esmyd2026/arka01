<?php

namespace App\Http\Controllers;

use App\Models\Ride;
use App\Services\Ride\RideReviewer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function __construct(private readonly RideReviewer $rideReviewer) {}

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
     * Lógica real en App\Services\Ride\RideReviewer (roadmap app móvil,
     * Hito 5).
     */
    public function store(Request $request, Ride $ride): RedirectResponse
    {
        $validated = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            // Motivo obligatorio solo si se baja de las 5 estrellas por
            // defecto (pedido explícito del usuario).
            'rating_reason_id' => ['required_if:rating,1,2,3,4', 'nullable', 'integer', 'exists:rating_reasons,id'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $this->rideReviewer->review(
            $ride,
            $request->user(),
            $validated['rating'],
            $validated['rating_reason_id'] ?? null,
            $validated['comment'] ?? null,
        );

        return back();
    }
}
