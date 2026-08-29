<?php

namespace App\Http\Controllers;

use App\Models\Ride;
use App\Services\Ride\RideMessageSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Chat temporal cliente↔conductor (sección 10 del roadmap de mejoras): solo
 * existe mientras la carrera está programada o en curso entre esas dos
 * personas puntuales — nunca antes de que el conductor acepte, ni después de
 * que la carrera termine o se cancele (Ride::chatIsOpen()). No hay pantalla
 * de "mis chats": cada carrera es su propio hilo, ya visible en Ride/Show.vue.
 * Lógica real en App\Services\Ride\RideMessageSender (roadmap app móvil,
 * Hito 5).
 */
class RideMessageController extends Controller
{
    public function __construct(private readonly RideMessageSender $rideMessageSender) {}

    /**
     * Los mensajes de rutina (respuestas rápidas + texto libre) no necesitan
     * Inertia — un POST por axios devuelve el mensaje recién creado y el
     * propio remitente lo agrega a su lista sin esperar el eco por
     * WebSocket, que sí le llega a la otra parte (ver Ride/Show.vue).
     */
    public function store(Request $request, Ride $ride): JsonResponse
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
        ]);
    }
}
